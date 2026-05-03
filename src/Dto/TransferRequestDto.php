<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TransferRequestDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'fromAccountId is required.')]
        #[Assert\Uuid(message: 'fromAccountId must be a valid UUID.')]
        public ?string $fromAccountId = null,
        #[Assert\NotBlank(message: 'toAccountId is required.')]
        #[Assert\Uuid(message: 'toAccountId must be a valid UUID.')]
        public ?string $toAccountId = null,
        #[Assert\NotBlank(message: 'amountMinor is required.')]
        #[Assert\Regex(pattern: '/^[1-9][0-9]*$/', message: 'amountMinor must be a positive integer string of minor units.')]
        public ?string $amountMinor = null,
    ) {
    }
}
