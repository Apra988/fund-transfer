<?php

declare(strict_types=1);

namespace App\Exception;

final class IdempotencyKeyMismatchException extends \RuntimeException
{
    public static function reusedWithDifferentPayload(): self
    {
        return new self('Idempotency-Key was already used with a different request body.');
    }
}
