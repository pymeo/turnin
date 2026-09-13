<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Coordination;

use App\Coordination\Application\Query\LinkedScheduleTimelines;
use App\Coordination\Domain\BusyInterval;
use App\Scheduling\Application\Query\GetCalendarTimeline;
use App\Scheduling\Application\Query\GetCalendarTimelineHandler;
use DateTimeImmutable;

final readonly class SchedulingLinkedScheduleTimelines implements LinkedScheduleTimelines
{
    public function __construct(private GetCalendarTimelineHandler $timeline)
    {
    }

    public function forUser(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $entries = [];
        foreach (($this->timeline)(new GetCalendarTimeline($userId, $from, $to))->entries as $entry) {
            if (!$entry->blocksAvailability) {
                continue;
            }
            $work = 'work_shift' === $entry->kind;
            $entries[] = new BusyInterval($entry->startsAt, $entry->endsAt, $work, $work ? $entry->displayLabel : 'Ocupado', $work ? $entry->workerAssignmentId : null, $work ? $entry->startsAt->format('Y-m-d') : null);
        }

        return $entries;
    }
}
