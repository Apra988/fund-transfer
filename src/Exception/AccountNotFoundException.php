<?php

declare(strict_types=1);

namespace App\Exception;

final class AccountNotFoundException extends \DomainException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Account not found: %s', $publicId));
    }

    public static function duringTransferLock(): self
    {
        return new self('Account not found.');
    }
}
