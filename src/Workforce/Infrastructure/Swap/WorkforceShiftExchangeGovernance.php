<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Swap;

use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\ShiftExchangePolicy;
use Doctrine\DBAL\Connection;

/**
 * Workforce answering "may this person approve changes of this pool?".
 *
 * The answer is a VERIFIED SupervisorAssignment for that exact pool — never a
 * role on the account, a link someone holds, or a pending request. Read on
 * every call, so stepping down takes effect on the next request.
 */
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
        return false !== $this->connection->fetchOne("SELECT 1 FROM workforce_supervisor_assignments WHERE supervisor_user_id = :supervisor AND swap_pool_id = :pool AND status = 'verified'", ['supervisor' => $supervisorUserId, 'pool' => $swapPoolId]);
    }

    public function supervisedPools(string $supervisorUserId): array
    {
        /** @var list<string> $pools */
        $pools = $this->connection->fetchFirstColumn("SELECT swap_pool_id FROM workforce_supervisor_assignments WHERE supervisor_user_id = :supervisor AND status = 'verified' ORDER BY swap_pool_id", ['supervisor' => $supervisorUserId]);

        return $pools;
    }

    public function approversOf(string $swapPoolId): array
    {
        /** @var list<string> $approvers */
        $approvers = $this->connection->fetchFirstColumn("SELECT supervisor_user_id FROM workforce_supervisor_assignments WHERE swap_pool_id = :pool AND status = 'verified' ORDER BY verified_at", ['pool' => $swapPoolId]);

        return $approvers;
    }
}
