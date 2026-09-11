<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/** Replaces one day's availability with the exact slots chosen in the UI. */
final readonly class ChangeAvailabilityDayHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private Availabilities $availabilities,
        private DeclareAvailabilityHandler $declare,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangeAvailabilityDay $command): void
    {
        if ([] === $command->swapPoolIds || [] === $command->shiftKinds) {
            throw new InvalidArgumentException('Selecciona al menos un turno y un servicio.');
        }

        $date = WorkDate::fromString($command->date);
        $groups = [];
        foreach (array_values(array_unique($command->swapPoolIds)) as $poolId) {
            $group = $this->workspace->requireGroup($command->workerId, $poolId);
            $groups[$group->poolId] = $group;
        }

        $kinds = [];
        foreach (array_values(array_unique($command->shiftKinds)) as $value) {
            $kind = ShiftKind::tryFrom($value);
            if (null === $kind || !\in_array($kind, ShiftKind::basic(), true)) {
                throw new InvalidArgumentException('Selecciona un turno válido.');
            }
            $kinds[$kind->value] = $kind;
        }

        $wanted = [];
        foreach ($groups as $group) {
            foreach ($kinds as $kind) {
                $wanted[$group->poolId.'|'.$kind->value] = true;
            }
        }

        $now = $this->clock->now();
        foreach ($this->availabilities->activeByWorkerOnDate($command->workerId, $date) as $slot) {
            if (!isset($wanted[$slot->swapPoolId().'|'.$slot->shiftKind()->value])) {
                $slot->withdraw($command->workerId, $now);
                $this->availabilities->save($slot);
            }
        }

        $byAssignment = [];
        foreach ($groups as $group) {
            $byAssignment[$group->assignmentId][] = $group->poolId;
        }
        foreach ($byAssignment as $assignmentId => $poolIds) {
            ($this->declare)(new DeclareAvailability(
                $command->workerId,
                $assignmentId,
                $command->date,
                $poolIds,
                array_keys($kinds),
            ));
        }
    }
}
