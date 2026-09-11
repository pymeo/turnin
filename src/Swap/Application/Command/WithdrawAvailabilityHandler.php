<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class WithdrawAvailabilityHandler
{
    public function __construct(private Availabilities $availabilities, private ClockInterface $clock)
    {
    }

    public function __invoke(WithdrawAvailability $command): void
    {
        $now = $this->clock->now();

        if (null !== $command->availabilityId) {
            $availability = $this->availabilities->byId($command->availabilityId);
            // Ownership is checked here and again inside the aggregate: an id
            // that belongs to somebody else must not even be loadable into a
            // mutation path.
            if (null === $availability || $availability->workerId() !== $command->workerId) {
                throw SwapAccessDenied::notYours();
            }
            $availability->withdraw($command->workerId, $now);
            $this->availabilities->save($availability);

            return;
        }

        if (null === $command->date) {
            throw new InvalidArgumentException('Indica qué disponibilidad quieres retirar.');
        }

        $date = WorkDate::fromString($command->date);
        $pools = [] === $command->swapPoolIds ? null : array_fill_keys($command->swapPoolIds, true);
        foreach ($this->availabilities->activeByWorkerOnDate($command->workerId, $date) as $availability) {
            if (null !== $pools && !isset($pools[$availability->swapPoolId()])) {
                continue;
            }
            $availability->withdraw($command->workerId, $now);
            $this->availabilities->save($availability);
        }
    }
}
