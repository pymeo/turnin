<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

use App\Coordination\Domain\ScheduleComparator;
use App\Coordination\Domain\ScheduleLinks;
use InvalidArgumentException;

final readonly class GetScheduleCoordinationHandler
{
    public function __construct(private ScheduleLinks $links, private LinkedScheduleTimelines $timelines, private CoordinationDisplayNames $names, private ScheduleComparator $comparator)
    {
    }

    public function __invoke(GetScheduleCoordination $query): ScheduleCoordinationView
    {
        $link = $this->links->activeFor($query->viewerId);
        if (null === $link) {
            throw new InvalidArgumentException('No tienes un calendario vinculado.');
        }
        $partnerId = $link->other($query->viewerId);
        $names = $this->names->forUsers([$partnerId]);
        $mine = $this->timelines->forUser($query->viewerId, $query->from, $query->to);
        $theirs = $this->timelines->forUser($partnerId, $query->from, $query->to);

        return new ScheduleCoordinationView($link->id(), $names[$partnerId] ?? 'Tu persona vinculada', $this->comparator->compare($query->from, $query->to, $mine, $theirs));
    }
}
