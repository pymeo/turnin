<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Persistence\Doctrine;

use App\Platform\Identity\Domain\Email;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserId;
use App\Platform\Identity\Domain\Users;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineUsers implements Users
{
    public function __construct(private Connection $connection)
    {
    }

    public function byEmail(Email $email): ?User
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_users WHERE email = :email', ['email' => $email->value]);

        return false === $row ? null : $this->map($row);
    }

    public function byId(UserId $id): ?User
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_users WHERE id = :id', ['id' => $id->value]);

        return false === $row ? null : $this->map($row);
    }

    public function save(User $user): void
    {
        $this->connection->insert('identity_users', ['id' => $user->id->value, 'email' => $user->email->value, 'password_hash' => $user->passwordHash, 'has_worker_profile' => $user->hasWorkerProfile, 'has_supervisor_profile' => $user->hasSupervisorProfile, 'created_at' => $user->createdAt], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean', 'created_at' => 'datetime_immutable']);
    }

    public function markWorkerProfile(UserId $id): void
    {
        $this->connection->executeStatement('UPDATE identity_users SET has_worker_profile = TRUE WHERE id = :id', ['id' => $id->value]);
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): User
    {
        return new User(new UserId($this->text($row['id'] ?? null)), new Email($this->text($row['email'] ?? null)), $this->text($row['password_hash'] ?? null), (bool) ($row['has_worker_profile'] ?? false), (bool) ($row['has_supervisor_profile'] ?? false), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
