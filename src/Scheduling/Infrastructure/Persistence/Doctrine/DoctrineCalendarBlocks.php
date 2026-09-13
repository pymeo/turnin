<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Persistence\Doctrine;

use App\Scheduling\Domain\CalendarBlock;
use App\Scheduling\Domain\CalendarBlocks;
use App\Scheduling\Domain\CalendarBlockSource;
use App\Scheduling\Domain\CalendarBlockType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class DoctrineCalendarBlocks implements CalendarBlocks
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(CalendarBlock $block): void
    {
        $this->connection->executeStatement(
            'INSERT INTO scheduling_calendar_blocks (id, worker_id, title, type, starts_at, ends_at, all_day, blocks_availability, source, external_calendar_id, external_event_id, external_updated_at, created_at, updated_at) VALUES (:id, :worker, :title, :type, :starts, :ends, :all_day, :blocks, :source, :calendar, :event, :external_updated, :created, :updated) ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title, type = EXCLUDED.type, starts_at = EXCLUDED.starts_at, ends_at = EXCLUDED.ends_at, all_day = EXCLUDED.all_day, blocks_availability = EXCLUDED.blocks_availability, external_updated_at = EXCLUDED.external_updated_at, updated_at = EXCLUDED.updated_at',
            [
                'id' => $block->id(),
                'worker' => $block->workerId(),
                'title' => $block->title(),
                'type' => $block->type()->value,
                'starts' => $block->startsAt(),
                'ends' => $block->endsAt(),
                'all_day' => $block->allDay(),
                'blocks' => $block->blocksAvailability(),
                'source' => $block->source()->value,
                'calendar' => $block->externalCalendarId(),
                'event' => $block->externalEventId(),
                'external_updated' => $block->externalUpdatedAt(),
                'created' => $block->createdAt(),
                'updated' => $block->updatedAt(),
            ],
            ['starts' => Types::DATETIMETZ_IMMUTABLE, 'ends' => Types::DATETIMETZ_IMMUTABLE, 'all_day' => Types::BOOLEAN, 'blocks' => Types::BOOLEAN, 'external_updated' => Types::DATETIMETZ_IMMUTABLE, 'created' => Types::DATETIMETZ_IMMUTABLE, 'updated' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function byExternalIdentity(string $workerId, string $calendarId, string $eventId): ?CalendarBlock
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM scheduling_calendar_blocks WHERE worker_id = :worker AND source = :source AND external_calendar_id = :calendar AND external_event_id = :event', ['worker' => $workerId, 'source' => CalendarBlockSource::GOOGLE_CALENDAR->value, 'calendar' => $calendarId, 'event' => $eventId]);

        return false === $row ? null : $this->map($row);
    }

    public function inRange(string $workerId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM scheduling_calendar_blocks WHERE worker_id = :worker AND starts_at < :to AND ends_at > :from ORDER BY starts_at, ends_at, id', ['worker' => $workerId, 'from' => $from, 'to' => $to], ['from' => Types::DATETIMETZ_IMMUTABLE, 'to' => Types::DATETIMETZ_IMMUTABLE]);

        return array_map($this->map(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): CalendarBlock
    {
        return CalendarBlock::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['worker_id'] ?? null),
            $this->text($row['title'] ?? null),
            CalendarBlockType::from($this->text($row['type'] ?? null)),
            new DateTimeImmutable($this->text($row['starts_at'] ?? null)),
            new DateTimeImmutable($this->text($row['ends_at'] ?? null)),
            $this->boolean($row['all_day'] ?? null),
            $this->boolean($row['blocks_availability'] ?? null),
            CalendarBlockSource::from($this->text($row['source'] ?? null)),
            $this->nullableText($row['external_calendar_id'] ?? null),
            $this->nullableText($row['external_event_id'] ?? null),
            null === ($row['external_updated_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['external_updated_at'])),
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function nullableText(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function boolean(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value;
    }
}
