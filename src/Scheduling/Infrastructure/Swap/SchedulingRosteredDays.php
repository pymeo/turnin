<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Swap;

use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\WorkDate;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Scheduling answering what Swap needs to know about a calendar: is this day
 * worked, and what does the shift look like.
 *
 * Publishing and offering are reads. Once the author accepts an offer, this
 * adapter atomically moves the copied shift snapshot between both calendars.
 */
final readonly class SchedulingRosteredDays implements RosteredDays
{
    public function __construct(private RosterDays $rosterDays, private RosterIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function dayFor(string $workerAssignmentId, string $date): RosteredDay
    {
        $day = $this->rosterDays->onDate($workerAssignmentId, WorkDate::fromString($date));

        return null === $day ? RosteredDay::unknown($workerAssignmentId, $date) : $this->project($workerAssignmentId, $day);
    }

    public function daysFor(array $assignmentAndDatePairs): array
    {
        if ([] === $assignmentAndDatePairs) {
            return [];
        }

        $assignments = [];
        $dates = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $assignments[$assignmentId] = true;
            $dates[] = $date;
        }
        sort($dates);

        // One range query for every assignment involved, then filtered to the
        // pairs that were asked for. A card list must not become a query per
        // card, and the roster repository is already range-scoped.
        $wanted = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $wanted[RosteredDay::keyFor($assignmentId, $date)] = true;
        }

        /** @var non-empty-list<string> $assignmentIds */
        $assignmentIds = array_keys($assignments);
        $found = [];
        foreach ($this->rosterDays->inRangeForAssignments($assignmentIds, WorkDate::fromString($dates[0]), WorkDate::fromString($dates[\count($dates) - 1])) as $day) {
            $key = RosteredDay::keyFor($day->workerAssignmentId(), (string) $day->date());
            if (isset($wanted[$key])) {
                $found[$key] = $this->project($day->workerAssignmentId(), $day);
            }
        }

        return $found;
    }

    public function inRangeForAssignments(array $workerAssignmentIds, string $from, string $to): array
    {
        if ([] === $workerAssignmentIds) {
            return [];
        }

        return array_map(
            fn (RosterDay $day): RosteredDay => $this->project($day->workerAssignmentId(), $day),
            $this->rosterDays->inRangeForAssignments(
                array_values(array_unique($workerAssignmentIds)),
                WorkDate::fromString($from),
                WorkDate::fromString($to),
            ),
        );
    }

    public function transferCoverage(string $fromAssignmentId, string $toAssignmentId, string $date): void
    {
        $workDate = WorkDate::fromString($date);
        $source = $this->rosterDays->onDate($fromAssignmentId, $workDate);
        $target = $this->rosterDays->onDate($toAssignmentId, $workDate);
        if (null === $source || !$source->isWorking() || (null !== $target && $target->isWorking())) {
            throw new InvalidArgumentException('Los calendarios han cambiado y este turno ya no puede cubrirse.');
        }
        $now = $this->clock->now();
        $segments = array_map(fn (ShiftSegment $segment): ShiftSegment => new ShiftSegment($this->ids->next(), $segment->presetId, $segment->labelSnapshot, $segment->abbreviationSnapshot, $segment->window, $segment->kind, $segment->position, $segment->colorSnapshot), $source->segments());
        $source->markRest(RosterSource::SWAP, $now);
        $covered = RosterDay::working($target?->id() ?? $this->ids->next(), $toAssignmentId, $workDate, $segments, RosterSource::SWAP, $now);
        $this->rosterDays->apply($fromAssignmentId, [$source], []);
        $this->rosterDays->apply($toAssignmentId, [$covered], []);
    }

    public function exchange(string $firstAssignmentId, string $firstDate, string $secondAssignmentId, string $secondDate): void
    {
        $firstWorkDate = WorkDate::fromString($firstDate);
        $secondWorkDate = WorkDate::fromString($secondDate);
        $first = $this->rosterDays->onDate($firstAssignmentId, $firstWorkDate);
        $second = $this->rosterDays->onDate($secondAssignmentId, $secondWorkDate);
        $firstTarget = $this->rosterDays->onDate($firstAssignmentId, $secondWorkDate);
        $secondTarget = $this->rosterDays->onDate($secondAssignmentId, $firstWorkDate);
        if (null === $first || !$first->isWorking() || null === $second || !$second->isWorking() || (null !== $firstTarget && $firstTarget->isWorking()) || (null !== $secondTarget && $secondTarget->isWorking())) {
            throw new InvalidArgumentException('Los calendarios han cambiado y estos turnos ya no se pueden intercambiar.');
        }

        $now = $this->clock->now();
        $firstSegments = $this->copySegments($first->segments());
        $secondSegments = $this->copySegments($second->segments());
        $first->markRest(RosterSource::SWAP, $now);
        $second->markRest(RosterSource::SWAP, $now);
        $firstReceives = RosterDay::working($firstTarget?->id() ?? $this->ids->next(), $firstAssignmentId, $secondWorkDate, $secondSegments, RosterSource::SWAP, $now);
        $secondReceives = RosterDay::working($secondTarget?->id() ?? $this->ids->next(), $secondAssignmentId, $firstWorkDate, $firstSegments, RosterSource::SWAP, $now);
        $this->rosterDays->apply($firstAssignmentId, [$first, $firstReceives], []);
        $this->rosterDays->apply($secondAssignmentId, [$second, $secondReceives], []);
    }

    /** @param list<ShiftSegment> $segments
     * @return list<ShiftSegment>
     */
    private function copySegments(array $segments): array
    {
        return array_map(fn (ShiftSegment $segment): ShiftSegment => new ShiftSegment($this->ids->next(), $segment->presetId, $segment->labelSnapshot, $segment->abbreviationSnapshot, $segment->window, $segment->kind, $segment->position, $segment->colorSnapshot), $segments);
    }

    private function project(string $assignmentId, RosterDay $day): RosteredDay
    {
        if ($day->isRest()) {
            return new RosteredDay($assignmentId, (string) $day->date(), RosteredDayState::REST);
        }

        $segment = $day->firstSegment();
        if (null === $segment) {
            return RosteredDay::unknown($assignmentId, (string) $day->date());
        }

        return new RosteredDay(
            $assignmentId,
            (string) $day->date(),
            RosteredDayState::WORKING,
            $day->id(),
            $segment->labelSnapshot,
            $segment->abbreviationSnapshot,
            (string) $segment->window->start,
            (string) $segment->window->end,
            $segment->endsNextDay(),
            $segment->colorSnapshot->value,
            $segment->kind,
        );
    }
}
