<?php

declare(strict_types=1);

namespace App\Service;

use App\Api\TransferResponse;
use App\Entity\Account;
use App\Entity\Transfer;
use App\Exception\AccountNotFoundException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransferException;
use App\Repository\AccountRepository;
use App\Repository\TransferRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

final class FundTransferService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountRepository $accounts,
        private TransferRepository $transfers,
        private AccountBalanceCache $balanceCache,
        private LoggerInterface $logger,
        #[Target('idempotency.cache')]
        private CacheItemPoolInterface $idempotency,
    ) {
    }

    public function transfer(
        string $fromPublicId,
        string $toPublicId,
        string $amountMinor,
        ?string $idempotencyKey,
    ): TransferResponse {
        if ($fromPublicId === $toPublicId) {
            throw InvalidTransferException::sameAccount();
        }
        if (!self::isPositiveMinorAmount($amountMinor)) {
            throw InvalidTransferException::invalidAmount();
        }

        if (null !== $idempotencyKey) {
            $cached = $this->readIdempotencyCache($idempotencyKey);
            if (null !== $cached) {
                return $cached;
            }
        }

        $this->em->beginTransaction();
        try {
            if (null !== $idempotencyKey) {
                $existing = $this->transfers->findOneByIdempotencyKey($idempotencyKey);
                if (null !== $existing) {
                    $this->em->commit();
                    $response = TransferResponse::fromEntity($existing);
                    $this->writeIdempotencyCache($idempotencyKey, $response);

                    return $response;
                }
            }

            $fromId = $this->accounts->findOneByPublicId($fromPublicId)?->getId();
            if (null === $fromId) {
                throw AccountNotFoundException::forPublicId($fromPublicId);
            }
            $toId = $this->accounts->findOneByPublicId($toPublicId)?->getId();
            if (null === $toId) {
                throw AccountNotFoundException::forPublicId($toPublicId);
            }

            $firstId = min($fromId, $toId);
            $secondId = max($fromId, $toId);

            $first = $this->em->find(Account::class, $firstId, LockMode::PESSIMISTIC_WRITE);
            $second = $this->em->find(Account::class, $secondId, LockMode::PESSIMISTIC_WRITE);
            if (!$first instanceof Account || !$second instanceof Account) {
                throw new \RuntimeException('Locked accounts missing after existence check.');
            }

            $from = $fromId === $firstId ? $first : $second;
            $to = $toId === $firstId ? $first : $second;

            if (bccomp($from->getBalanceMinor(), $amountMinor, 0) < 0) {
                throw new InsufficientFundsException();
            }

            $newFrom = bcsub($from->getBalanceMinor(), $amountMinor, 0);
            $newTo = bcadd($to->getBalanceMinor(), $amountMinor, 0);
            $from->setBalanceMinor($newFrom);
            $to->setBalanceMinor($newTo);

            $transfer = new Transfer(
                Uuid::v4()->toRfc4122(),
                $from,
                $to,
                $amountMinor,
                $idempotencyKey,
            );
            $this->em->persist($transfer);
            $this->em->flush();
            $this->em->commit();

            $this->logger->info('fund_transfer.completed', [
                'transfer_public_id' => $transfer->getPublicId(),
                'from' => $from->getPublicId(),
                'to' => $to->getPublicId(),
                'amount_minor' => $amountMinor,
            ]);

            $response = TransferResponse::fromEntity($transfer);
            $this->balanceCache->invalidate($from->getPublicId(), $to->getPublicId());
            if (null !== $idempotencyKey) {
                $this->writeIdempotencyCache($idempotencyKey, $response);
            }

            return $response;
        } catch (UniqueConstraintViolationException) {
            $this->rollbackIfActive();

            if (null !== $idempotencyKey) {
                $this->em->clear();
                $existing = $this->transfers->findOneByIdempotencyKey($idempotencyKey);
                if (null !== $existing) {
                    $response = TransferResponse::fromEntity($existing);
                    $this->writeIdempotencyCache($idempotencyKey, $response);

                    return $response;
                }
            }

            throw new \RuntimeException('Unexpected unique constraint violation during transfer.');
        } catch (\Throwable $e) {
            $this->rollbackIfActive();
            throw $e;
        }
    }

    private function readIdempotencyCache(string $idempotencyKey): ?TransferResponse
    {
        $item = $this->idempotency->getItem($this->idempotencyCacheKey($idempotencyKey));
        if (!$item->isHit()) {
            return null;
        }
        $raw = $item->get();
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return TransferResponse::fromArray($data);
    }

    private function writeIdempotencyCache(string $idempotencyKey, TransferResponse $response): void
    {
        $item = $this->idempotency->getItem($this->idempotencyCacheKey($idempotencyKey));
        $item->set(json_encode($response->toArray(), JSON_THROW_ON_ERROR));
        $item->expiresAfter(86400);
        $this->idempotency->save($item);
    }

    private function idempotencyCacheKey(string $idempotencyKey): string
    {
        return 'idem_'.hash('sha256', $idempotencyKey);
    }

    private function rollbackIfActive(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $this->em->rollback();
        }
    }

    private static function isPositiveMinorAmount(string $amount): bool
    {
        return 1 === preg_match('/^[1-9][0-9]*$/', $amount);
    }
}
