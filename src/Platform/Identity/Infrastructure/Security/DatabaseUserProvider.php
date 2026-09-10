<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<SecurityUser> */
final readonly class DatabaseUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_users WHERE email = :email', ['email' => mb_strtolower(trim($identifier))]);
        if (false === $row) {
            throw new UserNotFoundException();
        }

        return $this->map($row);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): SecurityUser
    {
        $passwordHash = $row['password_hash'] ?? null;

        return new SecurityUser($this->text($row['id'] ?? null), $this->text($row['email'] ?? null), \is_string($passwordHash) ? $passwordHash : null, (bool) ($row['has_worker_profile'] ?? false), (bool) ($row['has_supervisor_profile'] ?? false));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
