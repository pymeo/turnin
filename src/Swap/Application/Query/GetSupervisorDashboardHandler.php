<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\SwapAgreementSnapshots;
use App\Swap\Domain\SwapProposals;

/**
 * Agreements waiting for this supervisor, read from the proposals themselves.
 *
 * Deliberately not built from notifications: an agreement reached while the
 * pool had nobody verified is still pending approval, so it appears here the
 * moment its supervisor is verified. Only VERIFIED assignments open the page.
 */
final readonly class GetSupervisorDashboardHandler
{
    public function __construct(private ShiftExchangeGovernance $governance, private SwapProposals $proposals, private SwapAgreementSnapshots $agreements, private SwapAgreementViewFactory $views)
    {
    }

    public function __invoke(GetSupervisorDashboard $query): SupervisorDashboardView
    {
        $pools = $this->governance->supervisedPools($query->supervisorUserId);
        if ([] === $pools) {
            throw SwapAccessDenied::notASupervisor();
        }
        $pending = [];
        foreach ($this->proposals->awaitingApprovalInPools($pools) as $proposal) {
            $snapshot = $this->agreements->byProposal($proposal->id());
            if (null !== $snapshot) {
                $pending[] = $this->views->create($snapshot, $proposal);
            }
        }

        return new SupervisorDashboardView($pending);
    }
}
