<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Persistence\Doctrine;

use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;
use App\Scheduling\Domain\ShiftWindow;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineShiftPresets implements ShiftPresets
{
    public function __construct(private Connection $connection)
    {
    }

    public function forAssignment(string $workerAssignmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM scheduling_shift_presets WHERE worker_assignment_id = :assignment ORDER BY position, name',
            ['assignment' => $workerAssignmentId],
        );

        return array_map(fn (array $row): ShiftPreset => $this->hydrate($row), $rows);
    }

    public function byId(string $workerAssignmentId, string $presetId): ?ShiftPreset
    {
        // Always scoped by assignment: an id from somebody else's account has to
        // resolve to nothing, not to their shift.
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM scheduling_shift_presets WHERE id = :id AND worker_assignment_id = :assignment',
            ['id' => $presetId, 'assignment' => $workerAssignmentId],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(ShiftPreset $preset): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO scheduling_shift_presets (id, worker_assignment_id, name, abbreviation, starts_at, ends_at, kind, aliases, position, active, created_at, updated_at)
                     VALUES (:id, :assignment, :name, :abbreviation, :start, :end, :kind, :aliases, :position, :active, :created, :updated)
                ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, abbreviation = EXCLUDED.abbreviation,
                     starts_at = EXCLUDED.starts_at, ends_at = EXCLUDED.ends_at, kind = EXCLUDED.kind,
                     aliases = EXCLUDED.aliases, position = EXCLUDED.position, active = EXCLUDED.active,
                     updated_at = EXCLUDED.updated_at
                SQL,
            [
                'id' => $preset->id(),
                'assignment' => $preset->workerAssignmentId(),
                'name' => $preset->name(),
                'abbreviation' => $preset->abbreviation(),
                'start' => (string) $preset->window()->start,
                'end' => (string) $preset->window()->end,
                'kind' => $preset->kind()->value,
                'aliases' => json_encode($preset->aliases(), \JSON_THROW_ON_ERROR),
                'position' => $preset->position(),
                'active' => $preset->active(),
                'created' => $preset->createdAt()->format(DateTimeImmutable::ATOM),
                'updated' => $preset->updatedAt()->format(DateTimeImmutable::ATOM),
            ],
            ['active' => 'boolean'],
        );
    }

    public function saveAll(array $presets): void
    {
        if ([] === $presets) {
            return;
        }

        $this->connection->transactional(function () use ($presets): void {
            foreach ($presets as $preset) {
                $this->save($preset);
            }
        });
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ShiftPreset
    {
        /** @var list<string> $aliases */
        $aliases = json_decode($this->text($row['aliases'] ?? null) ?: '[]', true, 512, \JSON_THROW_ON_ERROR);

        return ShiftPreset::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['worker_assignment_id'] ?? null),
            $this->text($row['name'] ?? null),
            $this->text($row['abbreviation'] ?? null),
            ShiftWindow::fromStrings($this->text($row['starts_at'] ?? null), $this->text($row['ends_at'] ?? null)),
            ShiftKind::from($this->text($row['kind'] ?? null)),
            $aliases,
            is_numeric($row['position'] ?? null) ? (int) $row['position'] : 0,
            (bool) ($row['active'] ?? false),
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
