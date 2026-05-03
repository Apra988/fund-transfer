<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\AccountNotFoundException;
use App\Repository\AccountRepository;
use App\Service\AccountBalanceCache;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountBalanceCache $balanceCache,
    ) {
    }

    #[Route('/api/accounts/{publicId}', name: 'api_account_get', methods: ['GET'])]
    public function get(string $publicId): JsonResponse
    {
        $account = $this->accounts->findOneByPublicId($publicId);
        if (null === $account) {
            throw AccountNotFoundException::forPublicId($publicId);
        }

        $balance = $this->balanceCache->remember($publicId, fn (): string => $account->getBalanceMinor());

        return new JsonResponse([
            'accountId' => $account->getPublicId(),
            'balanceMinor' => $balance,
        ], Response::HTTP_OK);
    }
}
