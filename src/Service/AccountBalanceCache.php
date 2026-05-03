<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Short-TTL cache for read-heavy balance lookups; invalidated on successful transfers.
 */
final class AccountBalanceCache
{
    public function __construct(
        #[Target('account.cache')]
        private CacheInterface $cache,
    ) {
    }

    public function remember(string $accountPublicId, callable $loader): string
    {
        $key = $this->key($accountPublicId);

        return $this->cache->get($key, function (ItemInterface $item) use ($loader): string {
            return $loader();
        });
    }

    public function invalidate(string ...$accountPublicIds): void
    {
        foreach ($accountPublicIds as $id) {
            $this->cache->delete($this->key($id));
        }
    }

    private function key(string $accountPublicId): string
    {
        return hash('sha256', 'bal_'.$accountPublicId);
    }
}
