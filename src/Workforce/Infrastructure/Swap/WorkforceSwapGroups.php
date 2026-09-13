<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Swap;

use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapGroups;
use App\Workforce\Domain\WorkplaceTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Workforce answering Swap's only structural question: which groups can this
 * person exchange within.
 *
 * A membership is the answer, not the workplace. `SwapPoolResolver` already
 * decided what makes two people comparable — centre, category, specialty,
 * destination, functional area, employer — and materialised it as a pool; this
 * adapter reads that decision instead of repeating it. See docs/DOMAIN.md.
 */
final readonly class WorkforceSwapGroups implements SwapGroups
{
    public function __construct(private Connection $connection)
    {
    }

    public function activeFor(string $workerId): array
    {
        return $this->find($workerId);
    }

    public function forAssignment(string $workerId, string $assignmentId): array
    {
        return $this->find($workerId, assignmentId: $assignmentId);
    }

    public function membership(string $workerId, string $swapPoolId): ?SwapGroup
    {
        return $this->find($workerId, poolId: $swapPoolId)[0] ?? null;
    }

    /** @return list<SwapGroup> */
    private function find(string $workerId, ?string $assignmentId = null, ?string $poolId = null): array
    {
        $sql = <<<'SQL'
            SELECT p.id AS pool_id,
                   m.assignment_id,
                   m.is_primary,
                   w.name AS workplace_name,
                   w.autonomous_community,
                   COALESCE(u.name, '') AS destination_name,
                   COALESCE(c.name, '') AS category_name,
                   COALESCE(p.functional_area, '') AS functional_area
              FROM workforce_swap_pool_memberships m
              JOIN workforce_swap_pools p ON p.id = m.swap_pool_id
              JOIN workforce_worker_assignments a ON a.id = m.assignment_id
              JOIN workforce_workplaces w ON w.id = p.workplace_id
              LEFT JOIN workforce_organizational_units u ON u.id = p.organizational_unit_id
              LEFT JOIN workforce_staff_categories c ON c.id = p.staff_category_id
             WHERE m.worker_id = :worker
               AND m.active = TRUE
               AND p.active = TRUE
               AND a.active = TRUE
               AND (
                    u.id IS NOT NULL
                    OR NULLIF(BTRIM(COALESCE(p.functional_area, '')), '') IS NOT NULL
               )
            SQL;
        $parameters = ['worker' => $workerId];
        if (null !== $assignmentId) {
            $sql .= ' AND m.assignment_id = :assignment';
            $parameters['assignment'] = $assignmentId;
        }
        if (null !== $poolId) {
            $sql .= ' AND p.id = :pool';
            $parameters['pool'] = $poolId;
        }
        $sql .= ' ORDER BY m.is_primary DESC, w.name, u.name';

        return array_map(fn (array $row): SwapGroup => new SwapGroup(
            $this->text($row['pool_id'] ?? null),
            $this->text($row['assignment_id'] ?? null),
            $this->text($row['workplace_name'] ?? null),
            $this->text($row['destination_name'] ?? null),
            $this->text($row['category_name'] ?? null),
            (bool) ($row['is_primary'] ?? false),
            WorkplaceTimeZone::forAutonomousCommunity($this->nullable($row['autonomous_community'] ?? null)),
            $this->text($row['functional_area'] ?? null),
        ), $this->connection->fetchAllAssociative($sql, $parameters));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function nullable(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
