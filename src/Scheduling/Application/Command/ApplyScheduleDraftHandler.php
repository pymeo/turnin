<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterAccessDenied;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\DraftIntent;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\ScheduleDraftComposer;
use App\Scheduling\Domain\ScheduleDraftEntry;
use App\Scheduling\Domain\ScheduleDraftResolver;
use App\Scheduling\Domain\SegmentProposal;
use App\Scheduling\Domain\ShiftSegment;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Validates a draft against what is already stored and writes the result in one
 * transaction — or writes nothing.
 *
 * Applying a rotation to a quarter is around a hundred days. A hundred HTTP
 * calls would be slow, would half-apply on a dropped connection, and would make
 * "keep what I already have" impossible to honour, since each request would
 * only see its own day.
 */
final readonly class ApplyScheduleDraftHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private RosterDays $rosterDays,
        private ScheduleDraftComposer $composer,
        private ScheduleDraftResolver $resolver,
        private RosterIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ApplyScheduleDraft $command): ScheduleDraftApplied
    {
        $worker = $this->workspace->require($command->workerId, $command->assignmentId);
        $presets = $this->workspace->presetsFor($worker);
        $draft = $command->preparedDraft ?? $this->composer->compose($command->instructions, $presets, $command->source, $worker->assignmentId);
        if (null !== $draft->workerAssignmentId && $draft->workerAssignmentId !== $worker->assignmentId) {
            throw RosterAccessDenied::notOwned();
        }

        if ($draft->isEmpty()) {
            throw new RuntimeException('No hay ningún día que guardar.');
        }

        $from = $draft->firstDate() ?? throw new RuntimeException('No hay ningún día que guardar.');
        $to = $draft->lastDate() ?? $from;

        $existing = $this->rosterDays->inRange($worker->assignmentId, $from, $to);
        $resolved = $this->resolver->resolve($draft, $existing, $command->policy);

        $now = $this->clock->now();
        $days = [];
        $clear = [];

        foreach ($resolved->entriesToWrite() as $entry) {
            if (DraftIntent::CLEAR === $entry->intent) {
                $clear[] = $entry->date;
                continue;
            }
            $days[] = $this->dayFor($worker->assignmentId, $entry, $command, $now);
        }

        $this->rosterDays->apply($worker->assignmentId, $days, $clear);

        return new ScheduleDraftApplied(\count($days), \count($clear), $resolved->conflictCount(), (string) $from, (string) $to);
    }

    private function dayFor(string $assignmentId, ScheduleDraftEntry $entry, ApplyScheduleDraft $command, DateTimeImmutable $now): RosterDay
    {
        if (DraftIntent::REST === $entry->intent) {
            return RosterDay::rest($this->ids->next(), $assignmentId, $entry->date, $command->source, $now);
        }

        $segments = [];
        foreach ($entry->segments as $position => $proposal) {
            $segments[] = $this->segmentFor($proposal, $position);
        }

        return RosterDay::working($this->ids->next(), $assignmentId, $entry->date, $segments, $command->source, $now);
    }

    private function segmentFor(SegmentProposal $proposal, int $position): ShiftSegment
    {
        return new ShiftSegment($this->ids->next(), $proposal->presetId, $proposal->label, $proposal->abbreviation, $proposal->window, $proposal->kind, $position, $proposal->color);
    }
}
