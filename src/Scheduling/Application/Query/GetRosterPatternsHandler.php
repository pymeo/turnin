<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\PatternSlot;
use App\Scheduling\Domain\PatternSlotType;
use App\Scheduling\Domain\RosterPattern;
use App\Scheduling\Domain\RosterPatterns;
use App\Scheduling\Domain\ShiftPresetResolver;

final readonly class GetRosterPatternsHandler
{
    public function __construct(private RosterWorkspace $workspace, private RosterPatterns $patterns)
    {
    }

    /** @return list<RosterPatternView> */
    public function __invoke(GetRosterPatterns $query): array
    {
        $worker = $this->workspace->require($query->workerId);
        $presets = $this->workspace->presetsFor($worker);

        return array_map(fn (RosterPattern $pattern): RosterPatternView => $this->view($pattern, $presets), $this->patterns->forAssignment($worker->assignmentId));
    }

    private function view(RosterPattern $pattern, ShiftPresetResolver $presets): RosterPatternView
    {
        $slots = [];
        $abbreviations = [];
        foreach ($pattern->slots() as $slot) {
            $described = $this->describe($slot, $presets);
            $slots[] = $described;
            $abbreviations[] = $described['abbreviation'];
        }

        return new RosterPatternView($pattern->id(), $pattern->name(), $pattern->length(), implode(' · ', $abbreviations), $slots);
    }

    /** @return array{type: string, presetId: string|null, abbreviation: string, label: string} */
    private function describe(PatternSlot $slot, ShiftPresetResolver $presets): array
    {
        if (PatternSlotType::REST === $slot->type) {
            return ['type' => 'rest', 'presetId' => null, 'abbreviation' => 'L', 'label' => 'Libre'];
        }

        $preset = null === $slot->shiftPresetId ? null : $presets->byId($slot->shiftPresetId);

        return [
            'type' => 'shift',
            'presetId' => $slot->shiftPresetId,
            'abbreviation' => $preset?->abbreviation() ?? '?',
            'label' => $preset?->name() ?? 'Turno eliminado',
        ];
    }
}
