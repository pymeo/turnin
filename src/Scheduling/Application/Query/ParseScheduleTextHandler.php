<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Application\ScheduleDraftPreviewFactory;
use App\Scheduling\Domain\PatternSlotProposal;
use App\Scheduling\Domain\PatternSlotType;
use App\Scheduling\Domain\RosterMonth;
use App\Scheduling\Domain\ScheduleTextParser;

/**
 * A query, not a command, and that is the whole design: dictating never writes.
 * The transcript becomes a draft, the draft becomes a preview, and the worker
 * confirms — or corrects — before a single day changes.
 */
final readonly class ParseScheduleTextHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private RosterCalendar $calendar,
        private ScheduleTextParser $parser,
        private ScheduleDraftPreviewFactory $previews,
    ) {
    }

    public function __invoke(ParseScheduleText $query): ParsedScheduleView
    {
        $worker = $this->workspace->require($query->workerId);
        $month = null === $query->month ? $this->calendar->today($worker)->month() : RosterMonth::fromString($query->month);

        $reading = $this->parser->parse($query->text, $month, $this->workspace->presetsFor($worker), $query->source);

        $pattern = $reading->pattern;
        $slots = array_map(static fn (PatternSlotProposal $slot): array => [
            'type' => PatternSlotType::REST === $slot->type ? 'rest' : 'shift',
            'presetId' => $slot->presetId,
            'abbreviation' => $slot->abbreviation,
            'label' => $slot->label,
        ], null === $pattern ? [] : $pattern->slots);

        return new ParsedScheduleView(
            $this->previews->build($worker, $reading->draft, $query->policy, $reading->unrecognized),
            $slots,
            null === $pattern ? '' : $pattern->sequence(),
            $reading->understoodNothing(),
        );
    }
}
