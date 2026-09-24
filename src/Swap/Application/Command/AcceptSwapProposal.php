<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

/**
 * "Confirmar intercambio": of the shifts offered back, this is the one I can do.
 */
final readonly class AcceptSwapProposal
{
    public function __construct(public string $workerId, public string $proposalId, public string $optionId)
    {
    }
}
