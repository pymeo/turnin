<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\Availability;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * "Puedo trabajar este día.".
 *
 * An explicit statement, never inferred. It does not write to the calendar
 * either: saying you could work the 21st does not turn the 21st into a rest
 * day, because the two sentences are not the same and Scheduling owns the
 * second one.
 */
final readonly class DeclareAvailabilityHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private Availabilities $availabilities,
        private RosteredDays $rosteredDays,
        private SwapIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<string> the pools the worker is now available in */
    public function __invoke(DeclareAvailability $command): array
    {
        $groups = $this->resolveGroups($command);
        $date = WorkDate::fromString($command->date);
        $kinds = $this->resolveKinds($command);

        // Somebody already working that day cannot also cover a shift on it.
        // A day nobody has filled in is fine — the offer is itself the fact.
        if ($this->rosteredDays->dayFor($command->workerAssignmentId, (string) $date)->isWorking()) {
            throw new InvalidArgumentException('Ese día ya trabajas. Retira tu turno antes de ofrecerte.');
        }

        $now = $this->clock->now();
        $declared = [];
        foreach ($groups as $group) {
            foreach ($kinds as $kind) {
                $existing = $this->availabilities->forSlot($command->workerId, $group->poolId, $date, $kind);
                if (null !== $existing) {
                    $existing->reaffirm($now);
                    $this->availabilities->save($existing);
                    continue;
                }

                $availability = Availability::declare(
                    $this->ids->next(),
                    $command->workerId,
                    $command->workerAssignmentId,
                    $group->poolId,
                    $date,
                    $kind,
                    $this->workspace->today($group),
                    $now,
                );
                $this->availabilities->save($availability);
            }
            $declared[] = $group->poolId;
        }

        return $declared;
    }

    /** @return non-empty-list<ShiftKind> */
    private function resolveKinds(DeclareAvailability $command): array
    {
        if ([] === $command->shiftKinds) {
            return ShiftKind::offerable();
        }

        $kinds = [];
        foreach (array_unique($command->shiftKinds) as $value) {
            $kind = ShiftKind::tryFrom($value);
            if (null === $kind || !\in_array($kind, ShiftKind::offerable(), true)) {
                throw new InvalidArgumentException('Selecciona al menos un turno válido.');
            }
            $kinds[] = $kind;
        }

        /* @var non-empty-list<ShiftKind> $kinds */
        return $kinds;
    }

    /** @return non-empty-list<SwapGroup> */
    private function resolveGroups(DeclareAvailability $command): array
    {
        $reachable = $this->workspace->groupsForAssignment($command->workerId, $command->workerAssignmentId);
        if ([] === $reachable) {
            throw SwapAccessDenied::noAssignment();
        }
        if ([] === $command->swapPoolIds) {
            return $reachable;
        }

        $chosen = [];
        foreach (array_unique($command->swapPoolIds) as $poolId) {
            // Re-resolved against the session: a pool id from the browser is a
            // suggestion, not an entitlement. Anything that is not one of this
            // worker's groups stops here.
            $chosen[] = $this->workspace->requireGroupOfAssignment($command->workerId, $command->workerAssignmentId, $poolId);
        }

        return $chosen;
    }
}
