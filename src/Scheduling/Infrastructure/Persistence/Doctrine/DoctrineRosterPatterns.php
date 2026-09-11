<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Persistence\Doctrine;

use App\Scheduling\Domain\PatternSlot;
use App\Scheduling\Domain\PatternSlotType;
use App\Scheduling\Domain\RosterPattern;
use App\Scheduling\Domain\RosterPatterns;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineRosterPatterns implements RosterPatterns
{
    public function __construct(private Connection $connection)
    {
    }

    public function forAssignment(string $workerAssignmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.id, p.worker_assignment_id, p.name, p.created_at, p.updated_at,
                       s.position, s.slot_type, s.shift_preset_id
                  FROM scheduling_roster_patterns p
                  LEFT JOIN scheduling_roster_pattern_slots s ON s.pattern_id = p.id
                 WHERE p.worker_assignment_id = :assignment
                 ORDER BY p.created_at DESC, s.position
                SQL,
            ['assignment' => $workerAssignmentId],
        );

        return $this->hydrate($rows);
    }

    public function byId(string $workerAssignmentId, string $patternId): ?RosterPattern
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.id, p.worker_assignment_id, p.name, p.created_at, p.updated_at,
                       s.position, s.slot_type, s.shift_preset_id
                  FROM scheduling_roster_patterns p
                  LEFT JOIN scheduling_roster_pattern_slots s ON s.pattern_id = p.id
                 WHERE p.worker_assignment_id = :assignment AND p.id = :id
                 ORDER BY s.position
                SQL,
            ['assignment' => $workerAssignmentId, 'id' => $patternId],
        );

        return $this->hydrate($rows)[0] ?? null;
    }

    public function save(RosterPattern $pattern): void
    {
        $this->connection->transactional(function () use ($pattern): void {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO scheduling_roster_patterns (id, worker_assignment_id, name, created_at, updated_at)
                         VALUES (:id, :assignment, :name, :created, :updated)
                    ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, updated_at = EXCLUDED.updated_at
                    SQL,
                [
                    'id' => $pattern->id(),
                    'assignment' => $pattern->workerAssignmentId(),
                    'name' => $pattern->name(),
                    'created' => $pattern->createdAt()->format(DateTimeImmutable::ATOM),
                    'updated' => $pattern->updatedAt()->format(DateTimeImmutable::ATOM),
                ],
            );

            $this->connection->delete('scheduling_roster_pattern_slots', ['pattern_id' => $pattern->id()]);
            foreach ($pattern->slots() as $slot) {
                $this->connection->insert('scheduling_roster_pattern_slots', [
                    'pattern_id' => $pattern->id(),
                    'position' => $slot->position,
                    'slot_type' => $slot->type->value,
                    'shift_preset_id' => $slot->shiftPresetId,
                ]);
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RosterPattern>
     */
    private function hydrate(array $rows): array
    {
        /** @var array<string, array{row: array<string, mixed>, slots: list<PatternSlot>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = $this->text($row['id'] ?? null);
            $grouped[$id] ??= ['row' => $row, 'slots' => []];
            if (null === ($row['position'] ?? null)) {
                continue;
            }
            $position = is_numeric($row['position']) ? (int) $row['position'] : 0;
            $grouped[$id]['slots'][] = PatternSlotType::REST->value === $this->text($row['slot_type'] ?? null)
                ? PatternSlot::rest($position)
                : PatternSlot::shift($position, $this->text($row['shift_preset_id'] ?? null));
        }

        $patterns = [];
        foreach ($grouped as $id => $pattern) {
            if ([] === $pattern['slots']) {
                continue;
            }
            $row = $pattern['row'];
            $patterns[] = RosterPattern::restore(
                $id,
                $this->text($row['worker_assignment_id'] ?? null),
                $this->text($row['name'] ?? null),
                $pattern['slots'],
                new DateTimeImmutable($this->text($row['created_at'] ?? null)),
                new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
            );
        }

        return $patterns;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
