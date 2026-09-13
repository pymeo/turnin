<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\CalendarBlocks;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\WorkDate;
use DateTimeZone;

final readonly class GetCalendarTimelineHandler
{
    public function __construct(private RosterWorkspace $workspace, private RosterDays $rosterDays, private CalendarBlocks $blocks)
    {
    }

    public function __invoke(GetCalendarTimeline $query): CalendarTimelineView
    {
        $entries = [];
        $assignments = $this->workspace->activeAssignments($query->workerId);
        if ([] !== $assignments) {
            /** @var non-empty-list<string> $assignmentIds */
            $assignmentIds = array_map(static fn (AssignedWorker $assignment): string => $assignment->assignmentId, $assignments);
            $workers = [];
            foreach ($assignments as $assignment) {
                $workers[$assignment->assignmentId] = $assignment;
            }
            $fromDate = WorkDate::fromString($query->from->setTimezone(new DateTimeZone('UTC'))->modify('-1 day')->format('Y-m-d'));
            $toDate = WorkDate::fromString($query->to->setTimezone(new DateTimeZone('UTC'))->modify('+1 day')->format('Y-m-d'));
            foreach ($this->rosterDays->inRangeForAssignments($assignmentIds, $fromDate, $toDate) as $day) {
                $worker = $workers[$day->workerAssignmentId()] ?? null;
                if (null === $worker) {
                    continue;
                }
                foreach ($day->segments() as $segment) {
                    $interval = $segment->intervalOn($day->date(), $worker->timeZone());
                    if ($interval->startsAt >= $query->to || $interval->endsAt <= $query->from) {
                        continue;
                    }
                    $entries[] = new CalendarEntryView($segment->id, 'work_shift', $interval->startsAt, $interval->endsAt, false, $segment->labelSnapshot, $day->source()->value, true, $day->workerAssignmentId(), $day->id(), $segment->colorSnapshot->value);
                }
            }
        }
        foreach ($this->blocks->inRange($query->workerId, $query->from, $query->to) as $block) {
            $entries[] = new CalendarEntryView($block->id(), 'personal', $block->startsAt(), $block->endsAt(), $block->allDay(), $block->title(), $block->source()->value, $block->blocksAvailability());
        }
        usort($entries, static fn (CalendarEntryView $left, CalendarEntryView $right): int => [$left->startsAt, $left->endsAt, $left->id] <=> [$right->startsAt, $right->endsAt, $right->id]);

        return new CalendarTimelineView($entries);
    }
}
