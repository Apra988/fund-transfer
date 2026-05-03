<?php

declare(strict_types=1);

namespace App\Service;

use App\Api\TransferResponse;

/**
 * Stable digest of idempotent transfer intent (same key must map to the same payload).
 */
final class TransferIdempotencyFingerprint
{
    public static function hash(string $fromAccountId, string $toAccountId, string $amountMinor): string
    {
        $payload = json_encode(
            [
                'amountMinor' => $amountMinor,
                'fromAccountId' => $fromAccountId,
                'toAccountId' => $toAccountId,
            ],
            JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $payload);
    }

    public static function matchesResponse(TransferResponse $stored, string $fromAccountId, string $toAccountId, string $amountMinor): bool
    {
        return hash_equals(
            self::hash($fromAccountId, $toAccountId, $amountMinor),
            self::hash($stored->fromAccountId, $stored->toAccountId, $stored->amountMinor),
        );
    }
}
