<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class UserNotification
{
    private function __construct(
        private readonly string $id,
        private readonly string $recipientId,
        private readonly string $eventId,
        private readonly NotificationType $type,
        private readonly string $title,
        private readonly string $body,
        private readonly string $targetUrl,
        private ?DateTimeImmutable $readAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id) || '' === trim($recipientId) || '' === trim($eventId) || '' === trim($title) || '' === trim($body) || !str_starts_with($targetUrl, '/')) {
            throw new InvalidArgumentException('Una notificación necesita destinatario, contenido y destino interno válidos.');
        }
    }

    public static function create(string $id, string $recipientId, string $eventId, NotificationType $type, string $title, string $body, string $targetUrl, DateTimeImmutable $now): self
    {
        return new self($id, $recipientId, $eventId, $type, $title, $body, $targetUrl, null, $now);
    }

    public static function restore(string $id, string $recipientId, string $eventId, NotificationType $type, string $title, string $body, string $targetUrl, ?DateTimeImmutable $readAt, DateTimeImmutable $createdAt): self
    {
        return new self($id, $recipientId, $eventId, $type, $title, $body, $targetUrl, $readAt, $createdAt);
    }

    public function markRead(DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function recipientId(): string
    {
        return $this->recipientId;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function type(): NotificationType
    {
        return $this->type;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function targetUrl(): string
    {
        return $this->targetUrl;
    }

    public function readAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
