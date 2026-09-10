<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use LogicException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(private string $id, private string $email, private string $hash, private bool $worker, private bool $supervisor)
    {
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new LogicException('A security user needs an email.');
        }

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->hash;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hasWorkerProfile(): bool
    {
        return $this->worker;
    }

    public function hasSupervisorProfile(): bool
    {
        return $this->supervisor;
    }
}
