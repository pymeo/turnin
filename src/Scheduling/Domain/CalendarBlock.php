<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class CalendarBlock
{
    private function __construct(
        private readonly string $id,
        private readonly string $workerId,
        private string $title,
        private CalendarBlockType $type,
        private DateTimeImmutable $startsAt,
        private DateTimeImmutable $endsAt,
        private bool $allDay,
        private bool $blocksAvailability,
        private readonly CalendarBlockSource $source,
        private readonly ?string $externalCalendarId,
        private readonly ?string $externalEventId,
        private ?DateTimeImmutable $externalUpdatedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->guard();
    }

    public static function create(string $id, string $workerId, string $title, CalendarBlockType $type, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, bool $allDay, bool $blocksAvailability, CalendarBlockSource $source, DateTimeImmutable $now, ?string $externalCalendarId = null, ?string $externalEventId = null, ?DateTimeImmutable $externalUpdatedAt = null): self
    {
        return new self($id, $workerId, trim($title), $type, $startsAt, $endsAt, $allDay, $blocksAvailability, $source, $externalCalendarId, $externalEventId, $externalUpdatedAt, $now, $now);
    }

    public static function restore(string $id, string $workerId, string $title, CalendarBlockType $type, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, bool $allDay, bool $blocksAvailability, CalendarBlockSource $source, ?string $externalCalendarId, ?string $externalEventId, ?DateTimeImmutable $externalUpdatedAt, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt): self
    {
        return new self($id, $workerId, $title, $type, $startsAt, $endsAt, $allDay, $blocksAvailability, $source, $externalCalendarId, $externalEventId, $externalUpdatedAt, $createdAt, $updatedAt);
    }

    public function synchronize(string $title, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, bool $allDay, DateTimeImmutable $externalUpdatedAt, DateTimeImmutable $now): void
    {
        if (CalendarBlockSource::GOOGLE_CALENDAR !== $this->source) {
            throw new InvalidArgumentException('Only an external calendar block can be synchronized.');
        }
        if (null !== $this->externalUpdatedAt && $externalUpdatedAt <= $this->externalUpdatedAt) {
            return;
        }
        $this->title = trim($title);
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->allDay = $allDay;
        $this->externalUpdatedAt = $externalUpdatedAt;
        $this->updatedAt = $now;
        $this->guard();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function type(): CalendarBlockType
    {
        return $this->type;
    }

    public function startsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function allDay(): bool
    {
        return $this->allDay;
    }

    public function blocksAvailability(): bool
    {
        return $this->blocksAvailability;
    }

    public function source(): CalendarBlockSource
    {
        return $this->source;
    }

    public function externalCalendarId(): ?string
    {
        return $this->externalCalendarId;
    }

    public function externalEventId(): ?string
    {
        return $this->externalEventId;
    }

    public function externalUpdatedAt(): ?DateTimeImmutable
    {
        return $this->externalUpdatedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function guard(): void
    {
        $length = mb_strlen($this->title);
        if ($length < 1 || $length > 160) {
            throw new InvalidArgumentException('A calendar block title must contain between 1 and 160 characters.');
        }
        if ($this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('A calendar block must end after it starts.');
        }
        if (CalendarBlockSource::GOOGLE_CALENDAR === $this->source && (null === $this->externalCalendarId || null === $this->externalEventId)) {
            throw new InvalidArgumentException('An external calendar block needs its provider identity.');
        }
    }
}
