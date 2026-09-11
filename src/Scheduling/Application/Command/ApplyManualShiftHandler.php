<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraft;
use App\Scheduling\Domain\ScheduleDraftEntry;
use App\Scheduling\Domain\SegmentProposal;
use App\Scheduling\Domain\ShiftColor;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use InvalidArgumentException;

final readonly class ApplyManualShiftHandler
{
    public function __construct(private ApplyScheduleDraftHandler $apply)
    {
    }

    public function __invoke(ApplyManualShift $command): ScheduleDraftApplied
    {
        $kind = ShiftKind::tryFrom($command->kind) ?? ShiftKind::OTHER;
        $color = ShiftColor::tryFrom($command->colorKey) ?? throw new InvalidArgumentException('Elige un color de la paleta Turnin.');
        $entry = ScheduleDraftEntry::work(WorkDate::fromString($command->date), [new SegmentProposal(null, $command->label, $command->abbreviation, ShiftWindow::fromStrings($command->start, $command->end), $kind, $color)]);
        $draft = ScheduleDraft::of([$entry], RosterSource::MANUAL, $command->assignmentId);

        return ($this->apply)(new ApplyScheduleDraft($command->workerId, [], RosterSource::MANUAL, ConflictPolicy::REPLACE_EXISTING, $command->assignmentId, $draft));
    }
}
