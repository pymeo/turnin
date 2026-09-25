<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Domain\SwapAgreementSnapshots;
use App\Swap\Domain\SwapProposals;
use InvalidArgumentException;

final readonly class GetPublicSwapAgreementHandler
{
    public function __construct(private SwapAgreementSnapshots $agreements, private SwapProposals $proposals, private SwapAgreementViewFactory $views)
    {
    }

    public function __invoke(GetPublicSwapAgreement $query): SwapAgreementView
    {
        $snapshot = $this->agreements->byPublicToken($query->token) ?? throw new InvalidArgumentException('Ese enlace no existe.');
        $proposal = $this->proposals->byId($snapshot->proposalId()) ?? throw new InvalidArgumentException('Ese enlace no existe.');

        return $this->views->create($snapshot, $proposal);
    }
}
