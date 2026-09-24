<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\ShiftCompatibilityResolver;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapGroups;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalOption;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapTransaction;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Turns "I'll do yours, pick one of mine" into a proposal.
 *
 * Every identifier arrives from a browser, so every one of them is resolved
 * again here: the request, the pool it was published to, which of my calendars
 * reaches it, and that each offered shift is a real future shift of mine the
 * other person can actually work.
 */
final readonly class CreateSwapProposalHandler
{
    public function __construct(
        private SwapTransaction $transaction,
        private SwapWorkspace $workspace,
        private RosteredDays $days,
        private SwapGroups $groups,
        private ShiftCompatibilityResolver $compatibility,
        private SwapProposals $proposals,
        private SwapIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateSwapProposal $command): string
    {
        $id = $this->transaction->run(function () use ($command): string {
            $request = $this->workspace->requireVisibleRequest($command->workerId, $command->requestId);
            if (!$request->isOpen() || $request->workerId() === $command->workerId) {
                throw new InvalidArgumentException('Este turno ya no admite propuestas.');
            }
            $this->workspace->requireGroup($command->workerId, $request->swapPoolId());

            $requestedKey = RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate());
            $requested = $this->days->shiftsFor([[$request->workerAssignmentId(), (string) $request->workDate()]]);
            if ([] === $requested) {
                throw new InvalidArgumentException('Este turno ya no está disponible.');
            }

            $options = $this->options($command, $request->swapPoolId());
            $optionKeys = array_map(static fn (SwapProposalOption $option): string => RosteredDay::keyFor($option->assignmentId, (string) $option->workDate), $options);

            // I have to be able to do theirs, and they have to be able to do
            // every one of mine. Neither answer is a date comparison.
            $mine = $this->assignmentsOf($this->workspace->requireGroups($command->workerId));
            $verdict = $this->compatibility->assess($mine, $requested, [$requestedKey => true], array_fill_keys($optionKeys, true))[$requestedKey] ?? ShiftCompatibility::allowed();
            if (!$verdict->compatible) {
                throw new InvalidArgumentException($verdict->explanation);
            }

            $offered = $this->days->shiftsFor(array_map(static fn (SwapProposalOption $option): array => [$option->assignmentId, (string) $option->workDate], $options));
            $theirs = $this->assignmentsOf($this->groups->activeFor($request->workerId()));
            $theirVerdicts = $this->compatibility->assess($theirs, $offered, array_fill_keys($optionKeys, true), [$requestedKey => true]);
            foreach ($optionKeys as $key) {
                $blocked = $theirVerdicts[$key] ?? ShiftCompatibility::allowed();
                if (!$blocked->compatible) {
                    throw new InvalidArgumentException('Uno de los turnos que has elegido ya no lo puede hacer tu compañero.');
                }
            }

            $proposal = SwapProposal::proposeExchange($this->ids->next(), $request->id(), $request->workerId(), $command->workerId, $options[0]->assignmentId, $options, $this->clock->now());
            $this->proposals->save($proposal);

            return $proposal->id();
        });

        return \is_string($id) ? $id : '';
    }

    /**
     * @return non-empty-list<SwapProposalOption>
     */
    private function options(CreateSwapProposal $command, string $swapPoolId): array
    {
        $keys = array_values(array_filter($command->offeredShiftKeys, static fn (string $key): bool => '' !== trim($key)));
        if ([] === $keys) {
            throw new InvalidArgumentException('Elige al menos un turno tuyo para ofrecer a cambio.');
        }
        if (\count($keys) !== \count(array_unique($keys))) {
            throw new InvalidArgumentException('No puedes ofrecer el mismo turno dos veces.');
        }
        if (\count($keys) > SwapProposal::MAXIMUM_OPTIONS) {
            throw new InvalidArgumentException(\sprintf('Puedes ofrecer como mucho %d turnos.', SwapProposal::MAXIMUM_OPTIONS));
        }

        $pairs = [];
        foreach ($keys as $key) {
            $parts = explode('|', $key, 2);
            if (2 !== \count($parts)) {
                throw new InvalidArgumentException('Selecciona uno de tus turnos reales.');
            }
            // The membership is what proves the calendar is mine and reaches
            // that pool; an assignment id from a browser proves nothing.
            $this->workspace->requireGroupOfAssignment($command->workerId, $parts[0], $swapPoolId);
            $pairs[] = [$parts[0], (string) WorkDate::fromString($parts[1])];
        }

        $shifts = [];
        foreach ($this->days->shiftsFor($pairs) as $shift) {
            $shifts[$shift->dayKey()] = $shift;
        }

        $options = [];
        foreach ($pairs as [$assignmentId, $date]) {
            $shift = $shifts[RosteredDay::keyFor($assignmentId, $date)] ?? throw new InvalidArgumentException('Uno de los turnos que has elegido ya no existe en tu calendario.');
            $options[] = new SwapProposalOption($this->ids->next(), $assignmentId, $shift->rosterDayId, WorkDate::fromString($date));
        }

        return $options;
    }

    /**
     * @param list<SwapGroup> $groups
     *
     * @return list<string>
     */
    private function assignmentsOf(array $groups): array
    {
        return array_values(array_unique(array_map(static fn (SwapGroup $group): string => $group->assignmentId, $groups)));
    }
}
