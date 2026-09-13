<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

use App\Coordination\Application\Query\GetScheduleCoordination;
use App\Coordination\Application\Query\GetScheduleCoordinationHandler;
use InvalidArgumentException;

final readonly class RequestCoordinationDaysHandler
{
    public function __construct(private GetScheduleCoordinationHandler $coordination, private CoordinationSwapRequests $swaps)
    {
    }

    /** @return list<string> */
    public function __invoke(RequestCoordinationDays $command): array
    {
        $view = ($this->coordination)(new GetScheduleCoordination($command->workerId, $command->from, $command->to));
        $allowed = [];
        foreach ($view->days as $day) {
            if (null !== $day->actionableShift && null !== $day->actionableShift->assignmentId && null !== $day->actionableShift->date) {
                $allowed[$day->actionableShift->assignmentId.'|'.$day->actionableShift->date] = [$day->actionableShift->assignmentId, $day->actionableShift->date];
            }
        }
        $selected = array_values(array_unique($command->assignmentAndDates));
        if ([] === $selected) {
            throw new InvalidArgumentException('Selecciona al menos un día.');
        }
        $requestIds = [];
        foreach ($selected as $selection) {
            if (!isset($allowed[$selection])) {
                throw new InvalidArgumentException('Uno de los días ya no es una oportunidad disponible.');
            }
            [$assignmentId, $date] = $allowed[$selection];
            $requestIds[] = $this->swaps->open($command->workerId, $assignmentId, $date);
        }

        return $requestIds;
    }
}
