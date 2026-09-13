<?php

declare(strict_types=1);

namespace App\Coordination\Infrastructure\Persistence\Doctrine;

use App\Coordination\Domain\ScheduleLink;
use App\Coordination\Domain\ScheduleLinks;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineScheduleLinks implements ScheduleLinks
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(ScheduleLink $link): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO coordination_schedule_links (id, first_user_id, second_user_id, created_at, revoked_at)
                 VALUES (:id, :first, :second, :created, :revoked)
            ON CONFLICT (id) DO UPDATE SET revoked_at = EXCLUDED.revoked_at
            SQL, ['id' => $link->id(), 'first' => $link->firstUserId(), 'second' => $link->secondUserId(), 'created' => $link->createdAt()->format(DateTimeImmutable::ATOM), 'revoked' => $link->revokedAt()?->format(DateTimeImmutable::ATOM)]);
    }

    public function activeFor(string $userId): ?ScheduleLink
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM coordination_schedule_links WHERE (first_user_id = :user OR second_user_id = :user) AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1', ['user' => $userId]);

        return false === $row ? null : $this->restore($row);
    }

    public function activeBetween(string $userA, string $userB): ?ScheduleLink
    {
        [$first, $second] = ScheduleLink::pair($userA, $userB);
        $row = $this->connection->fetchAssociative('SELECT * FROM coordination_schedule_links WHERE first_user_id = :first AND second_user_id = :second AND revoked_at IS NULL LIMIT 1', ['first' => $first, 'second' => $second]);

        return false === $row ? null : $this->restore($row);
    }

    /** @param array<string, mixed> $row */
    private function restore(array $row): ScheduleLink
    {
        return ScheduleLink::restore($this->text($row['id'] ?? null), $this->text($row['first_user_id'] ?? null), $this->text($row['second_user_id'] ?? null), new DateTimeImmutable($this->text($row['created_at'] ?? null)), null !== ($row['revoked_at'] ?? null) ? new DateTimeImmutable($this->text($row['revoked_at'])) : null);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
