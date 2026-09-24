<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapTransaction;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Saying no, from either side.
 *
 * Saying yes is a different command: it needs the chosen shift and a full
 * revalidation, and folding both into one "decision" is how the old handler
 * ended up branching on strings. See AcceptSwapProposal.
 */
final readonly class DecideSwapProposalHandler
{
    public function __construct(private SwapTransaction $transaction, private SwapProposals $proposals, private ClockInterface $clock)
    {
    }

    public function __invoke(DecideSwapProposal $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $proposal = $this->proposals->byIdForUpdate($command->proposalId) ?? throw new InvalidArgumentException('La propuesta no existe.');
            $now = $this->clock->now();
            match ($command->decision) {
                'withdraw' => SwapProposalStatus::WITHDRAWN === $proposal->status() ? null : $proposal->withdraw($command->workerId, $now),
                'reject' => SwapProposalStatus::REJECTED === $proposal->status() ? null : $proposal->reject($command->workerId, $now),
                default => throw new InvalidArgumentException('La decisión no es válida.'),
            };
            $this->proposals->save($proposal);
        });
    }
}
