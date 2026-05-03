<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransferRepository::class)]
#[ORM\Table(
    name: 'transfer',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'UNIQ_TRANSFER_IDEMP_OWNER_KEY', columns: ['idempotency_owner', 'idempotency_key']),
    ],
)]
class Transfer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Account $fromAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Account $toAccount;

    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private string $amountMinor;

    #[ORM\Column(length: 128, options: ['default' => ''])]
    private string $idempotencyOwner = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $publicId,
        Account $fromAccount,
        Account $toAccount,
        string $amountMinor,
        ?string $idempotencyKey,
        string $idempotencyOwner = '',
    ) {
        $this->publicId = $publicId;
        $this->fromAccount = $fromAccount;
        $this->toAccount = $toAccount;
        $this->amountMinor = $amountMinor;
        $this->idempotencyKey = $idempotencyKey;
        $this->idempotencyOwner = $idempotencyOwner;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getFromAccount(): Account
    {
        return $this->fromAccount;
    }

    public function getToAccount(): Account
    {
        return $this->toAccount;
    }

    public function getAmountMinor(): string
    {
        return $this->amountMinor;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getIdempotencyOwner(): string
    {
        return $this->idempotencyOwner;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
