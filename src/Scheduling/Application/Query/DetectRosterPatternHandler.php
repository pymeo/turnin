<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\PatternSlotProposal;
use App\Scheduling\Domain\PatternSlotType;
use App\Scheduling\Domain\RepeatingRosterPatternDetector;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterMonth;

/**
 * Offers to finish the job once the worker has entered enough days by hand for
 * a rotation to be unmistakable. Looks two months back so a rotation that
 * straddles a month boundary is still visible.
 */
final readonly class DetectRosterPatternHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private RosterDays $rosterDays,
        private RosterCalendar $calendar,
        private RepeatingRosterPatternDetector $detector,
    ) {
    }

    public function __invoke(DetectRosterPattern $query): ?DetectedPatternView
    {
        $worker = $this->workspace->require($query->workerId, $query->assignmentId);
        $month = null === $query->month ? $this->calendar->today($worker)->month() : RosterMonth::fromString($query->month);

        $days = $this->rosterDays->inRange($worker->assignmentId, $month->previous()->firstDay(), $month->next()->lastDay());
        $detected = $this->detector->detect($days);
        if (null === $detected) {
            return null;
        }

        return new DetectedPatternView(
            $detected->length(),
            $detected->sequence(),
            (string) $detected->repeatsFrom,
            $detected->observedCycles,
            array_map(static fn (PatternSlotProposal $slot): array => [
                'type' => PatternSlotType::REST === $slot->type ? 'rest' : 'shift',
                'presetId' => $slot->presetId,
                'abbreviation' => $slot->abbreviation,
                'label' => $slot->label,
            ], $detected->slots),
        );
    }
}
