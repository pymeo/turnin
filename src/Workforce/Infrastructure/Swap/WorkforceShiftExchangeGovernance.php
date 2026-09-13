<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Swap;

use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\ShiftExchangePolicy;
use Doctrine\DBAL\Connection;

final readonly class WorkforceShiftExchangeGovernance implements ShiftExchangeGovernance
{
    public function __construct(private Connection $connection)
    {
    }

    public function policyFor(string $swapPoolId): ShiftExchangePolicy
    {
        $row = $this->connection->fetchAssociative('SELECT requires_approval, allows_coverage FROM workforce_shift_exchange_policies WHERE swap_pool_id = :pool', ['pool' => $swapPoolId]);

        return new ShiftExchangePolicy($swapPoolId, false !== $row && (bool) $row['requires_approval'], false !== $row && (bool) $row['allows_coverage']);
    }

    public function canApprove(string $supervisorUserId, string $swapPoolId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM workforce_swap_supervisors WHERE supervisor_user_id = :supervisor AND swap_pool_id = :pool AND active = TRUE', ['supervisor' => $supervisorUserId, 'pool' => $swapPoolId]);
    }

    public function supervisedPools(string $supervisorUserId): array
    {
        /** @var list<string> $pools */
        $pools = $this->connection->fetchFirstColumn('SELECT swap_pool_id FROM workforce_swap_supervisors WHERE supervisor_user_id = :supervisor AND active = TRUE ORDER BY swap_pool_id', ['supervisor' => $supervisorUserId]);

        return $pools;
    }
}
