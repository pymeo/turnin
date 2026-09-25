<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use DateTimeImmutable;

interface UserNotifications
{
    /** False means that the same event was already delivered to this person. */
    public function addIfMissing(UserNotification $notification): bool;

    public function byIdForRecipient(string $id, string $recipientId): ?UserNotification;

    public function save(UserNotification $notification): void;

    /** @return list<UserNotification> */
    public function recentFor(string $recipientId, int $limit = 40): array;

    public function unreadCount(string $recipientId): int;

    public function markAllRead(string $recipientId, DateTimeImmutable $now): void;
}
