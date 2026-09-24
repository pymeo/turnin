<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Swap;

use App\Scheduling\Domain\AssignedWorkers;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\WorkDate;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\RosteredShift;
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
    public function __construct(private RosterDays $rosterDays, private AssignedWorkers $workers, private RosterIdGenerator $ids, private ClockInterface $clock)
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

    public function shiftsInRange(array $workerAssignmentIds, string $from, string $to): array
    {
        if ([] === $workerAssignmentIds) {
            return [];
        }

        $assignments = array_values(array_unique($workerAssignmentIds));
        $zones = $this->workers->timeZonesFor($assignments);
        $shifts = [];
        foreach ($this->rosterDays->inRangeForAssignments($assignments, WorkDate::fromString($from), WorkDate::fromString($to)) as $day) {
            $zone = $zones[$day->workerAssignmentId()] ?? null;
            if (!$day->isWorking() || null === $zone) {
                continue;
            }
            foreach ($day->segments() as $segment) {
                $interval = $segment->intervalOn($day->date(), $zone);
                $shifts[] = new RosteredShift(
                    $day->workerAssignmentId(),
                    $day->id(),
                    (string) $day->date(),
                    $interval->startsAt,
                    $interval->endsAt,
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

        return $shifts;
    }

    public function shiftsFor(array $assignmentAndDatePairs): array
    {
        if ([] === $assignmentAndDatePairs) {
            return [];
        }

        $assignments = [];
        $dates = [];
        $wanted = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $assignments[$assignmentId] = true;
            $dates[] = $date;
            $wanted[RosteredDay::keyFor($assignmentId, $date)] = true;
        }
        sort($dates);

        return array_values(array_filter(
            $this->shiftsInRange(array_keys($assignments), $dates[0], $dates[\count($dates) - 1]),
            static fn (RosteredShift $shift): bool => isset($wanted[$shift->dayKey()]),
        ));
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

    public function exchange(string $firstSourceAssignmentId, string $firstDate, string $secondSourceAssignmentId, string $secondDate, string $firstCalendarAssignmentId, string $secondCalendarAssignmentId): void
    {
        $firstWorkDate = WorkDate::fromString($firstDate);
        $secondWorkDate = WorkDate::fromString($secondDate);
        $first = $this->rosterDays->onDate($firstSourceAssignmentId, $firstWorkDate);
        $second = $this->rosterDays->onDate($secondSourceAssignmentId, $secondWorkDate);
        if ($firstWorkDate->equals($secondWorkDate)
            && $firstSourceAssignmentId === $firstCalendarAssignmentId
            && $secondSourceAssignmentId === $secondCalendarAssignmentId) {
            if (null === $first || !$first->isWorking() || null === $second || !$second->isWorking()) {
                throw new InvalidArgumentException('Los calendarios han cambiado y estos turnos ya no se pueden intercambiar.');
            }

            $now = $this->clock->now();
            $firstSegments = $this->copySegments($first->segments());
            $secondSegments = $this->copySegments($second->segments());
            $first->assign($secondSegments, RosterSource::SWAP, $now);
            $second->assign($firstSegments, RosterSource::SWAP, $now);
            $this->rosterDays->apply($firstSourceAssignmentId, [$first], []);
            $this->rosterDays->apply($secondSourceAssignmentId, [$second], []);

            return;
        }

        $firstTarget = $this->rosterDays->onDate($firstCalendarAssignmentId, $secondWorkDate);
        $secondTarget = $this->rosterDays->onDate($secondCalendarAssignmentId, $firstWorkDate);
        if (null === $first || !$first->isWorking() || null === $second || !$second->isWorking()) {
            throw new InvalidArgumentException('Los calendarios han cambiado y estos turnos ya no se pueden intercambiar.');
        }

        $now = $this->clock->now();
        $firstSegments = $this->copySegments($first->segments());
        $secondSegments = $this->copySegments($second->segments());
        $firstCurrentSource = $firstCalendarAssignmentId === $firstSourceAssignmentId ? null : $this->rosterDays->onDate($firstCalendarAssignmentId, $firstWorkDate);
        $secondCurrentSource = $secondCalendarAssignmentId === $secondSourceAssignmentId ? null : $this->rosterDays->onDate($secondCalendarAssignmentId, $secondWorkDate);
        $firstWasCopiedToCurrentCalendar = $this->sameWorkingHours($first, $firstCurrentSource);
        $secondWasCopiedToCurrentCalendar = $this->sameWorkingHours($second, $secondCurrentSource);
        $firstExisting = $firstWorkDate->equals($secondWorkDate) && ($firstCalendarAssignmentId === $firstSourceAssignmentId || $firstWasCopiedToCurrentCalendar) ? [] : ($firstTarget?->segments() ?? []);
        $secondExisting = $firstWorkDate->equals($secondWorkDate) && ($secondCalendarAssignmentId === $secondSourceAssignmentId || $secondWasCopiedToCurrentCalendar) ? [] : ($secondTarget?->segments() ?? []);
        $firstReceivesSegments = $this->appendSegments($firstExisting, $secondSegments);
        $secondReceivesSegments = $this->appendSegments($secondExisting, $firstSegments);
        $first->markRest(RosterSource::SWAP, $now);
        $second->markRest(RosterSource::SWAP, $now);
        if ($firstWasCopiedToCurrentCalendar && !$firstWorkDate->equals($secondWorkDate)) {
            $firstCurrentSource?->markRest(RosterSource::SWAP, $now);
        }
        if ($secondWasCopiedToCurrentCalendar && !$firstWorkDate->equals($secondWorkDate)) {
            $secondCurrentSource?->markRest(RosterSource::SWAP, $now);
        }
        $firstReceives = RosterDay::working($firstTarget?->id() ?? $this->ids->next(), $firstCalendarAssignmentId, $secondWorkDate, $firstReceivesSegments, RosterSource::SWAP, $now);
        $secondReceives = RosterDay::working($secondTarget?->id() ?? $this->ids->next(), $secondCalendarAssignmentId, $firstWorkDate, $secondReceivesSegments, RosterSource::SWAP, $now);

        $this->rosterDays->apply($firstSourceAssignmentId, [$first], []);
        $this->rosterDays->apply($secondSourceAssignmentId, [$second], []);
        if ($firstWasCopiedToCurrentCalendar && null !== $firstCurrentSource && !$firstWorkDate->equals($secondWorkDate)) {
            $this->rosterDays->apply($firstCalendarAssignmentId, [$firstCurrentSource], []);
        }
        if ($secondWasCopiedToCurrentCalendar && null !== $secondCurrentSource && !$firstWorkDate->equals($secondWorkDate)) {
            $this->rosterDays->apply($secondCalendarAssignmentId, [$secondCurrentSource], []);
        }
        $this->rosterDays->apply($firstCalendarAssignmentId, [$firstReceives], []);
        $this->rosterDays->apply($secondCalendarAssignmentId, [$secondReceives], []);
    }

    /** @param list<ShiftSegment> $segments
     * @return list<ShiftSegment>
     */
    private function copySegments(array $segments): array
    {
        return array_map(fn (ShiftSegment $segment): ShiftSegment => new ShiftSegment($this->ids->next(), $segment->presetId, $segment->labelSnapshot, $segment->abbreviationSnapshot, $segment->window, $segment->kind, $segment->position, $segment->colorSnapshot), $segments);
    }

    /**
     * A calendar day may contain several real, non-overlapping stretches. The
     * compatibility service has already rejected overlaps, so an existing
     * morning does not prevent receiving an evening shift on the same date.
     *
     * @param list<ShiftSegment> $existing
     * @param list<ShiftSegment> $received
     *
     * @return list<ShiftSegment>
     */
    private function appendSegments(array $existing, array $received): array
    {
        $nextPosition = [] === $existing ? 0 : max(array_map(static fn (ShiftSegment $segment): int => $segment->position, $existing)) + 1;
        foreach ($received as $offset => $segment) {
            $existing[] = new ShiftSegment(
                $this->ids->next(),
                $segment->presetId,
                $segment->labelSnapshot,
                $segment->abbreviationSnapshot,
                $segment->window,
                $segment->kind,
                $nextPosition + $offset,
                $segment->colorSnapshot,
            );
        }

        return $existing;
    }

    private function sameWorkingHours(RosterDay $source, ?RosterDay $candidate): bool
    {
        if (null === $candidate || !$candidate->isWorking() || \count($source->segments()) !== \count($candidate->segments())) {
            return false;
        }

        foreach ($source->segments() as $position => $segment) {
            $candidateSegment = $candidate->segments()[$position] ?? null;
            if (null === $candidateSegment || !$segment->sameLocalHoursAs($candidateSegment)) {
                return false;
            }
        }

        return true;
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
