<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence\Doctrine;

use App\Notification\Domain\NotificationType;
use App\Notification\Domain\UserNotification;
use App\Notification\Domain\UserNotifications;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineUserNotifications implements UserNotifications
{
    public function __construct(private Connection $connection)
    {
    }

    public function addIfMissing(UserNotification $notification): bool
    {
        return 1 === $this->connection->executeStatement(
            'INSERT INTO notification_user_notifications (id, recipient_id, event_id, type, title, body, target_url, read_at, created_at) VALUES (:id, :recipient, :event, :type, :title, :body, :target, NULL, :created) ON CONFLICT (recipient_id, event_id) DO NOTHING',
            $this->parameters($notification),
        );
    }

    public function byIdForRecipient(string $id, string $recipientId): ?UserNotification
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM notification_user_notifications WHERE id = :id AND recipient_id = :recipient', ['id' => $id, 'recipient' => $recipientId]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(UserNotification $notification): void
    {
        $this->connection->executeStatement('UPDATE notification_user_notifications SET read_at = :read WHERE id = :id AND recipient_id = :recipient', [
            'read' => $notification->readAt()?->format(DateTimeImmutable::ATOM),
            'id' => $notification->id(),
            'recipient' => $notification->recipientId(),
        ]);
    }

    public function recentFor(string $recipientId, int $limit = 40): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM notification_user_notifications WHERE recipient_id = :recipient ORDER BY created_at DESC LIMIT :limit', ['recipient' => $recipientId, 'limit' => max(1, min(100, $limit))], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map($this->hydrate(...), $rows);
    }

    public function unreadCount(string $recipientId): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM notification_user_notifications WHERE recipient_id = :recipient AND read_at IS NULL', ['recipient' => $recipientId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function markAllRead(string $recipientId, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE notification_user_notifications SET read_at = :read WHERE recipient_id = :recipient AND read_at IS NULL', ['read' => $now->format(DateTimeImmutable::ATOM), 'recipient' => $recipientId]);
    }

    /** @return array<string, string> */
    private function parameters(UserNotification $notification): array
    {
        return [
            'id' => $notification->id(), 'recipient' => $notification->recipientId(), 'event' => $notification->eventId(),
            'type' => $notification->type()->value, 'title' => $notification->title(), 'body' => $notification->body(),
            'target' => $notification->targetUrl(), 'created' => $notification->createdAt()->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): UserNotification
    {
        return UserNotification::restore(
            $this->text($row['id'] ?? null), $this->text($row['recipient_id'] ?? null), $this->text($row['event_id'] ?? null), NotificationType::from($this->text($row['type'] ?? null)),
            $this->text($row['title'] ?? null), $this->text($row['body'] ?? null), $this->text($row['target_url'] ?? null),
            null === ($row['read_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['read_at'] ?? null)), new DateTimeImmutable($this->text($row['created_at'] ?? null)),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
