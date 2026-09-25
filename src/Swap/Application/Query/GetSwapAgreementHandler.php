<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Domain\SwapAgreementSnapshots;
use App\Swap\Domain\SwapProposals;
use InvalidArgumentException;

final readonly class GetSwapAgreementHandler
{
    public function __construct(private SwapAgreementSnapshots $agreements, private SwapProposals $proposals, private SwapAgreementViewFactory $views)
    {
    }

    public function __invoke(GetSwapAgreement $query): SwapAgreementView
    {
        $proposal = $this->proposals->byId($query->proposalId) ?? throw new InvalidArgumentException('Ese cambio no existe.');
        if (!\in_array($query->workerId, [$proposal->requestOwnerId(), $proposal->proposerId()], true)) {
            throw new SwapAccessDenied('No participas en este cambio.');
        }
        $snapshot = $this->agreements->byProposal($proposal->id()) ?? throw new InvalidArgumentException('Ese cambio todavía no se ha acordado.');

        return $this->views->create($snapshot, $proposal);
    }
}
