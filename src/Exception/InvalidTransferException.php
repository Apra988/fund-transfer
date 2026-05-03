<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidTransferException extends \DomainException
{
    public static function sameAccount(): self
    {
        return new self('Source and destination accounts must differ.');
    }

    public static function invalidAmount(): self
    {
        return new self('Amount must be a positive integer of minor units.');
    }
}
