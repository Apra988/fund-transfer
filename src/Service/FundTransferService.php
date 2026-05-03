<?php

declare(strict_types=1);

namespace App\Service;

use App\Api\TransferResponse;
use App\Entity\Account;
use App\Entity\Transfer;
use App\Exception\AccountNotFoundException;
use App\Exception\IdempotencyKeyMismatchException;
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
    /** Maximum BIGINT UNSIGNED minor units stored in Doctrine string columns */
    private const MAX_UNSIGNED_BIGINT = '18446744073709551615';

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
        string $idempotencyOwner = '',
    ): TransferResponse {
        if ($fromPublicId === $toPublicId) {
            throw InvalidTransferException::sameAccount();
        }
        if (!self::isPositiveMinorAmount($amountMinor)) {
            throw InvalidTransferException::invalidAmount();
        }
        if (bccomp($amountMinor, self::MAX_UNSIGNED_BIGINT, 0) > 0) {
            throw InvalidTransferException::amountExceedsStorageLimit();
        }

        $idempotencyOwner = self::normalizeIdempotencyOwner($idempotencyOwner);

        if (null !== $idempotencyKey) {
            $cached = $this->readIdempotencyCache($idempotencyOwner, $idempotencyKey, $fromPublicId, $toPublicId, $amountMinor);
            if (null !== $cached) {
                return $cached;
            }
        }

        $this->em->beginTransaction();
        try {
            if (null !== $idempotencyKey) {
                $existing = $this->transfers->findOneByIdempotencyOwnerAndKey($idempotencyOwner, $idempotencyKey);
                if (null !== $existing) {
                    $stored = TransferResponse::fromEntity($existing);
                    if (!TransferIdempotencyFingerprint::matchesResponse($stored, $fromPublicId, $toPublicId, $amountMinor)) {
                        $this->em->rollback();
                        throw IdempotencyKeyMismatchException::reusedWithDifferentPayload();
                    }
                    $this->em->commit();
                    $this->writeIdempotencyCache($idempotencyOwner, $idempotencyKey, $stored);

                    return $stored;
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
                throw AccountNotFoundException::duringTransferLock();
            }

            $from = $fromId === $firstId ? $first : $second;
            $to = $toId === $firstId ? $first : $second;

            if (bccomp($from->getBalanceMinor(), $amountMinor, 0) < 0) {
                throw new InsufficientFundsException();
            }

            $newFrom = bcsub($from->getBalanceMinor(), $amountMinor, 0);
            $newTo = bcadd($to->getBalanceMinor(), $amountMinor, 0);
            if (bccomp($newTo, self::MAX_UNSIGNED_BIGINT, 0) > 0) {
                throw InvalidTransferException::balanceWouldOverflow();
            }
            $from->setBalanceMinor($newFrom);
            $to->setBalanceMinor($newTo);

            $transfer = new Transfer(
                Uuid::v4()->toRfc4122(),
                $from,
                $to,
                $amountMinor,
                $idempotencyKey,
                $idempotencyOwner,
            );
            $this->em->persist($transfer);
            $this->em->flush();
            $this->em->commit();

            $this->logger->info('fund_transfer.completed', [
                'transfer_public_id' => $transfer->getPublicId(),
                'from' => $from->getPublicId(),
                'to' => $to->getPublicId(),
                'amount_minor' => $amountMinor,
                'idem_owner_hash_prefix' => '' === $idempotencyOwner ? '' : substr($idempotencyOwner, 0, 16),
            ]);

            $response = TransferResponse::fromEntity($transfer);
            $this->balanceCache->primeBalances([
                $from->getPublicId() => $newFrom,
                $to->getPublicId() => $newTo,
            ]);
            if (null !== $idempotencyKey) {
                $this->writeIdempotencyCache($idempotencyOwner, $idempotencyKey, $response);
            }

            return $response;
        } catch (UniqueConstraintViolationException) {
            $this->rollbackIfActive();

            if (null !== $idempotencyKey) {
                $this->em->clear();
                $existing = $this->transfers->findOneByIdempotencyOwnerAndKey($idempotencyOwner, $idempotencyKey);
                if (null !== $existing) {
                    $stored = TransferResponse::fromEntity($existing);
                    if (!TransferIdempotencyFingerprint::matchesResponse($stored, $fromPublicId, $toPublicId, $amountMinor)) {
                        throw IdempotencyKeyMismatchException::reusedWithDifferentPayload();
                    }
                    $response = $stored;
                    $this->writeIdempotencyCache($idempotencyOwner, $idempotencyKey, $response);

                    return $response;
                }
            }

            throw new \RuntimeException('Unexpected unique constraint violation during transfer.');
        } catch (\Throwable $e) {
            $this->rollbackIfActive();
            throw $e;
        }
    }

    private function readIdempotencyCache(
        string $idempotencyOwner,
        string $idempotencyKey,
        string $fromPublicId,
        string $toPublicId,
        string $amountMinor,
    ): ?TransferResponse {
        $item = $this->idempotency->getItem($this->idempotencyCacheKey($idempotencyOwner, $idempotencyKey));
        if (!$item->isHit()) {
            return null;
        }
        $raw = $item->get();
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($data['transferId'], $data['fromAccountId'], $data['toAccountId'], $data['amountMinor'], $data['createdAt'])) {
            return null;
        }
        $stored = TransferResponse::fromArray($data);
        if (!TransferIdempotencyFingerprint::matchesResponse($stored, $fromPublicId, $toPublicId, $amountMinor)) {
            throw IdempotencyKeyMismatchException::reusedWithDifferentPayload();
        }

        return $stored;
    }

    private function writeIdempotencyCache(string $idempotencyOwner, string $idempotencyKey, TransferResponse $response): void
    {
        $item = $this->idempotency->getItem($this->idempotencyCacheKey($idempotencyOwner, $idempotencyKey));
        $item->set(json_encode($response->toArray(), JSON_THROW_ON_ERROR));
        $item->expiresAfter(86400);
        $this->idempotency->save($item);
    }

    private function idempotencyCacheKey(string $idempotencyOwner, string $idempotencyKey): string
    {
        return 'idem_'.hash('sha256', $idempotencyOwner."\0".$idempotencyKey);
    }

    private static function normalizeIdempotencyOwner(string $owner): string
    {
        if ('' === $owner) {
            return '';
        }

        return hash('sha256', $owner);
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
