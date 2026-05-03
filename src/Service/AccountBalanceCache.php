<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Short-TTL balance reads; primes known-good balances post-transfer to tighten read-after-write semantics.
 */
final class AccountBalanceCache
{
    private const TTL_SECONDS = 30;

    public function __construct(
        #[Target('account.cache')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function remember(string $accountPublicId, callable $loader): string
    {
        $key = $this->key($accountPublicId);
        $item = $this->pool->getItem($key);
        if ($item->isHit()) {
            return (string) $item->get();
        }

        $value = $loader();
        $item->set($value);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->pool->save($item);

        return $value;
    }

    public function invalidate(string ...$accountPublicIds): void
    {
        foreach ($accountPublicIds as $id) {
            $this->pool->deleteItem($this->key($id));
        }
    }

    /**
     * @param array<string, string> $accountPublicIdToBalanceMinor
     */
    public function primeBalances(array $accountPublicIdToBalanceMinor): void
    {
        foreach ($accountPublicIdToBalanceMinor as $publicId => $balanceMinor) {
            $item = $this->pool->getItem($this->key($publicId));
            $item->set($balanceMinor);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->pool->save($item);
        }
    }

    private function key(string $accountPublicId): string
    {
        return hash('sha256', 'bal_'.$accountPublicId);
    }
}
