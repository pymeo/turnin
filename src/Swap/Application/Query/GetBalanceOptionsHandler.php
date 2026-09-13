<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\OpportunityScoreWeights;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;
use InvalidArgumentException;

final readonly class GetBalanceOptionsHandler
{
    public function __construct(private ExchangeBalances $balances, private SwapWorkspace $workspace, private SwapRequests $requests, private RosteredDays $days, private WorkerDisplayNames $names)
    {
    }

    public function __invoke(GetBalanceOptions $query): BalanceOptionsView
    {
        $balance = $this->balances->byId($query->balanceId) ?? throw new InvalidArgumentException('El saldo no existe.');
        if ($balance->creditorWorkerId() !== $query->workerId) {
            throw new InvalidArgumentException('Este saldo no está a tu favor.');
        }
        $groups = $this->workspace->requireGroups($query->workerId);
        $open = $this->requests->openByWorker($balance->owingWorkerId(), $this->workspace->today($groups[0]));
        $options = [];
        foreach ($open as $request) {
            try {
                $group = $this->workspace->requireGroup($query->workerId, $request->swapPoolId());
            } catch (SwapAccessDenied) {
                continue;
            }
            $target = $this->days->dayFor($group->assignmentId, (string) $request->workDate());
            $day = $this->days->dayFor($request->workerAssignmentId(), (string) $request->workDate());
            if (!$day->isWorking() || $target->isWorking() || $day->durationMinutes() > $balance->availableMinutes()) {
                continue;
            }
            [$preferenceScore, $reasons] = $balance->preference()?->match($request->workDate(), $day->shiftKind, $day->durationMinutes()) ?? [0, []];
            $reasons[] = 'tienes un intercambio pendiente con este profesional';
            $options[] = new BalanceOptionView($request->id(), WorkDateLabel::headline($request->workDate()), $day->hours(), $day->durationLabel(), $day->durationMinutes(), $day->shiftLabel, $group->label(), $balance->availableMinutes() - $day->durationMinutes(), OpportunityScoreWeights::PENDING_EXCHANGE + $preferenceScore, $reasons);
        }
        usort($options, static fn (BalanceOptionView $a, BalanceOptionView $b): int => $b->score <=> $a->score);
        $names = $this->names->forWorkers([$balance->owingWorkerId()]);

        return new BalanceOptionsView($balance->id(), $names[$balance->owingWorkerId()] ?? 'Un compañero', $balance->availableMinutes(), $options);
    }
}
