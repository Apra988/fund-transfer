<?php

declare(strict_types=1);

namespace App\Tests\Application;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Clears Redis/array-backed transfer rate-limit state so tests do not interfere with each other.
 */
abstract class ApiApplicationTestCase extends WebTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createApiClient(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $pool = static::getContainer()->get('rate_limiter.transfer_write');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }

        return $client;
    }
}
