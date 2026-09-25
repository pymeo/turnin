<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\Supervision\SwapPoolDescription;
use App\Workforce\Domain\Supervision\SwapPoolTeam;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Doctrine\DBAL\Connection;

/**
 * Who is in a pool right now, with the same definition of "active" that
 * WorkforceSwapGroups uses to decide who can see whose shifts: an active
 * membership, on an active assignment, in an active pool.
 */
final readonly class DoctrineSwapPoolTeams implements SwapPoolTeams
{
    private const string ACTIVE_MEMBERSHIP = <<<'SQL'
          FROM workforce_swap_pool_memberships m
          JOIN workforce_swap_pools p ON p.id = m.swap_pool_id
          JOIN workforce_worker_assignments a ON a.id = m.assignment_id
         WHERE m.active = TRUE AND p.active = TRUE AND a.active = TRUE
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function team(string $swapPoolId): SwapPoolTeam
    {
        /** @var list<string> $members */
        $members = $this->connection->fetchFirstColumn('SELECT DISTINCT m.worker_id '.self::ACTIVE_MEMBERSHIP.' AND m.swap_pool_id = :pool', ['pool' => $swapPoolId]);

        return new SwapPoolTeam($swapPoolId, $members);
    }

    public function poolsOf(string $workerId): array
    {
        /** @var list<string> $pools */
        $pools = $this->connection->fetchFirstColumn('SELECT m.swap_pool_id '.self::ACTIVE_MEMBERSHIP.' AND m.worker_id = :worker GROUP BY m.swap_pool_id ORDER BY bool_or(m.is_primary) DESC, m.swap_pool_id', ['worker' => $workerId]);

        return $pools;
    }

    public function describe(string $swapPoolId): ?SwapPoolDescription
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT w.name AS workplace_name,
                   COALESCE(u.name, '') AS destination_name,
                   COALESCE(c.name, '') AS category_name,
                   COALESCE(p.functional_area, '') AS functional_area
              FROM workforce_swap_pools p
              JOIN workforce_workplaces w ON w.id = p.workplace_id
              LEFT JOIN workforce_organizational_units u ON u.id = p.organizational_unit_id
              LEFT JOIN workforce_staff_categories c ON c.id = p.staff_category_id
             WHERE p.id = :pool
            SQL, ['pool' => $swapPoolId]);
        if (false === $row) {
            return null;
        }
        $workplace = $this->text($row['workplace_name'] ?? null);
        $where = '' !== $this->text($row['destination_name'] ?? null) ? $this->text($row['destination_name'] ?? null) : $this->text($row['functional_area'] ?? null);
        $parts = array_values(array_filter([$where, $this->text($row['category_name'] ?? null)], static fn (string $part): bool => '' !== trim($part)));

        return new SwapPoolDescription($swapPoolId, $workplace, [] === $parts ? $workplace : implode(' · ', $parts));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
