<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Persistence\Doctrine;

use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterDayState;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftColor;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Days and their segments are written with DBAL rather than mapped as an ORM
 * association, for the same reason WorkerOnboardingDraft is: the aggregate owns
 * a child collection, and an ORM association would need a Doctrine Collection
 * on a Domain class — which is precisely the dependency deptrac exists to stop.
 *
 * It also makes the operation that matters cheap. Rolling a rotation over a
 * quarter is four statements here, whatever the range, instead of a hundred
 * entity graphs.
 */
final readonly class DoctrineRosterDays implements RosterDays
{
    public function __construct(private Connection $connection)
    {
    }

    public function inRange(string $workerAssignmentId, WorkDate $from, WorkDate $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT d.id, d.work_date, d.state, d.source, d.created_at, d.updated_at,
                       s.id AS segment_id, s.shift_preset_id, s.label_snapshot, s.abbreviation_snapshot,
                       s.starts_at, s.ends_at, s.kind, s.position, s.color_key_snapshot
                  FROM scheduling_roster_days d
                  LEFT JOIN scheduling_roster_segments s ON s.roster_day_id = d.id
                 WHERE d.worker_assignment_id = :assignment
                   AND d.work_date BETWEEN :from AND :to
                 ORDER BY d.work_date, s.position
                SQL,
            ['assignment' => $workerAssignmentId, 'from' => (string) $from, 'to' => (string) $to],
        );

        return $this->hydrate($workerAssignmentId, $rows);
    }

    public function inRangeForAssignments(array $workerAssignmentIds, WorkDate $from, WorkDate $to): array
    {
        if ([] === $workerAssignmentIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT d.id, d.worker_assignment_id, d.work_date, d.state, d.source, d.created_at, d.updated_at,
                       s.id AS segment_id, s.shift_preset_id, s.label_snapshot, s.abbreviation_snapshot,
                       s.starts_at, s.ends_at, s.kind, s.position, s.color_key_snapshot
                  FROM scheduling_roster_days d
                  LEFT JOIN scheduling_roster_segments s ON s.roster_day_id = d.id
                 WHERE d.worker_assignment_id IN (:assignments)
                   AND d.work_date BETWEEN :from AND :to
                 ORDER BY d.work_date, d.worker_assignment_id, s.position
                SQL,
            ['assignments' => $workerAssignmentIds, 'from' => (string) $from, 'to' => (string) $to],
            ['assignments' => ArrayParameterType::STRING],
        );
        $byAssignment = [];
        foreach ($rows as $row) {
            $byAssignment[$this->text($row['worker_assignment_id'] ?? null)][] = $row;
        }

        $days = [];
        foreach ($byAssignment as $assignmentId => $assignmentRows) {
            array_push($days, ...$this->hydrate($assignmentId, $assignmentRows));
        }
        usort($days, static fn (RosterDay $left, RosterDay $right): int => $left->date()->dayNumber() <=> $right->date()->dayNumber());

        return $days;
    }

    public function onDate(string $workerAssignmentId, WorkDate $date): ?RosterDay
    {
        return $this->inRange($workerAssignmentId, $date, $date)[0] ?? null;
    }

    public function firstFrom(string $workerAssignmentId, WorkDate $from, int $withinDays): ?RosterDay
    {
        return $this->inRange($workerAssignmentId, $from, $from->plusDays(max(0, $withinDays)))[0] ?? null;
    }

    public function countFor(string $workerAssignmentId): int
    {
        return $this->number($this->connection->fetchOne('SELECT COUNT(*) FROM scheduling_roster_days WHERE worker_assignment_id = :assignment', ['assignment' => $workerAssignmentId]));
    }

    public function apply(string $workerAssignmentId, array $days, array $datesToClear): void
    {
        if ([] === $days && [] === $datesToClear) {
            return;
        }

        $this->connection->transactional(function () use ($workerAssignmentId, $days, $datesToClear): void {
            $replaced = array_map(static fn (RosterDay $day): string => (string) $day->date(), $days);
            $cleared = array_map(static fn (WorkDate $date): string => (string) $date, $datesToClear);

            $this->deleteDates($workerAssignmentId, array_values(array_unique([...$replaced, ...$cleared])));
            $this->insertDays($workerAssignmentId, $days);
            $this->insertSegments($days);
        });
    }

    /** @param list<string> $dates */
    private function deleteDates(string $workerAssignmentId, array $dates): void
    {
        if ([] === $dates) {
            return;
        }

        // Segments go with the day: the cascade is declared on the foreign key
        // so a stray segment cannot outlive its day even under a manual delete.
        $this->connection->executeStatement(
            'DELETE FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date IN (:dates)',
            ['assignment' => $workerAssignmentId, 'dates' => $dates],
            ['dates' => ArrayParameterType::STRING],
        );
    }

    /** @param list<RosterDay> $days */
    private function insertDays(string $workerAssignmentId, array $days): void
    {
        if ([] === $days) {
            return;
        }

        $values = [];
        $parameters = [];
        foreach ($days as $index => $day) {
            $values[] = \sprintf('(:id%1$d, :assignment, :date%1$d, :state%1$d, :source%1$d, :created%1$d, :updated%1$d)', $index);
            $parameters['id'.$index] = $day->id();
            $parameters['date'.$index] = (string) $day->date();
            $parameters['state'.$index] = $day->state()->value;
            $parameters['source'.$index] = $day->source()->value;
            $parameters['created'.$index] = $day->createdAt()->format(DateTimeImmutable::ATOM);
            $parameters['updated'.$index] = $day->updatedAt()->format(DateTimeImmutable::ATOM);
        }
        $parameters['assignment'] = $workerAssignmentId;

        $this->connection->executeStatement(
            'INSERT INTO scheduling_roster_days (id, worker_assignment_id, work_date, state, source, created_at, updated_at) VALUES '.implode(', ', $values),
            $parameters,
        );
    }

    /** @param list<RosterDay> $days */
    private function insertSegments(array $days): void
    {
        $values = [];
        $parameters = [];
        $index = 0;

        foreach ($days as $day) {
            foreach ($day->segments() as $segment) {
                $values[] = \sprintf('(:sid%1$d, :day%1$d, :preset%1$d, :label%1$d, :abbr%1$d, :start%1$d, :end%1$d, :kind%1$d, :position%1$d, :color%1$d)', $index);
                $parameters['sid'.$index] = $segment->id;
                $parameters['day'.$index] = $day->id();
                $parameters['preset'.$index] = $segment->presetId;
                $parameters['label'.$index] = $segment->labelSnapshot;
                $parameters['abbr'.$index] = $segment->abbreviationSnapshot;
                $parameters['start'.$index] = (string) $segment->window->start;
                $parameters['end'.$index] = (string) $segment->window->end;
                $parameters['kind'.$index] = $segment->kind->value;
                $parameters['position'.$index] = $segment->position;
                $parameters['color'.$index] = $segment->colorSnapshot->value;
                ++$index;
            }
        }

        if ([] === $values) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO scheduling_roster_segments (id, roster_day_id, shift_preset_id, label_snapshot, abbreviation_snapshot, starts_at, ends_at, kind, position, color_key_snapshot) VALUES '.implode(', ', $values),
            $parameters,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RosterDay>
     */
    private function hydrate(string $workerAssignmentId, array $rows): array
    {
        /** @var array<string, array{row: array<string, mixed>, segments: list<ShiftSegment>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = $this->text($row['id'] ?? null);
            $grouped[$id] ??= ['row' => $row, 'segments' => []];
            if (null !== ($row['segment_id'] ?? null)) {
                $grouped[$id]['segments'][] = new ShiftSegment(
                    $this->text($row['segment_id'] ?? null),
                    $this->nullable($row['shift_preset_id'] ?? null),
                    $this->text($row['label_snapshot'] ?? null),
                    $this->text($row['abbreviation_snapshot'] ?? null),
                    ShiftWindow::fromStrings($this->text($row['starts_at'] ?? null), $this->text($row['ends_at'] ?? null)),
                    ShiftKind::from($this->text($row['kind'] ?? null)),
                    $this->number($row['position'] ?? null),
                    ShiftColor::from($this->text($row['color_key_snapshot'] ?? null)),
                );
            }
        }

        $days = [];
        foreach ($grouped as $id => $day) {
            $row = $day['row'];
            $days[] = RosterDay::restore(
                $id,
                $workerAssignmentId,
                WorkDate::fromString($this->text($row['work_date'] ?? null)),
                RosterDayState::from($this->text($row['state'] ?? null)),
                $day['segments'],
                RosterSource::from($this->text($row['source'] ?? null)),
                new DateTimeImmutable($this->text($row['created_at'] ?? null)),
                new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
            );
        }

        return $days;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function nullable(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
