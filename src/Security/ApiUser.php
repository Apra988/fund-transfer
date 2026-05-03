<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class ApiUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private string $identifier,
        private array $roles,
    ) {
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
