<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Transfer;

final readonly class TransferResponse
{
    public function __construct(
        public string $transferId,
        public string $fromAccountId,
        public string $toAccountId,
        public string $amountMinor,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(Transfer $t): self
    {
        return new self(
            $t->getPublicId(),
            $t->getFromAccount()->getPublicId(),
            $t->getToAccount()->getPublicId(),
            $t->getAmountMinor(),
            $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['transferId'],
            (string) $data['fromAccountId'],
            (string) $data['toAccountId'],
            (string) $data['amountMinor'],
            (string) $data['createdAt'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transferId' => $this->transferId,
            'fromAccountId' => $this->fromAccountId,
            'toAccountId' => $this->toAccountId,
            'amountMinor' => $this->amountMinor,
            'createdAt' => $this->createdAt,
        ];
    }
}
