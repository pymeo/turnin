<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraft;
use App\Scheduling\Domain\ScheduleDraftEntry;
use App\Scheduling\Domain\ScheduleDraftResolver;
use App\Scheduling\Domain\SegmentProposal;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The rule the whole slice is built to protect: a day the worker already filled
 * in is never overwritten unless they explicitly said so.
 */
final class ScheduleDraftResolverTest extends TestCase
{
    private const DATE = '2026-09-05';

    public function test_skip_existing_leaves_the_night_shift_alone(): void
    {
        $resolved = $this->resolve(ConflictPolicy::SKIP_EXISTING);

        self::assertSame(1, $resolved->conflictCount());
        self::assertSame(0, $resolved->affectedDays());
        self::assertSame('Noche 22:00–08:00', $resolved->conflicts()[0]->existingDescription());
    }

    public function test_replace_existing_writes_the_morning_over_it(): void
    {
        $resolved = $this->resolve(ConflictPolicy::REPLACE_EXISTING);

        self::assertSame(1, $resolved->conflictCount());
        self::assertSame(1, $resolved->affectedDays());
        self::assertSame('M', $resolved->entriesToWrite()[0]->abbreviation());
    }

    public function test_the_default_policy_is_to_keep_what_the_worker_already_told_us(): void
    {
        self::assertSame(ConflictPolicy::SKIP_EXISTING, ConflictPolicy::fromRequest(null));
        self::assertSame(ConflictPolicy::SKIP_EXISTING, ConflictPolicy::fromRequest('nonsense'));
        self::assertSame(ConflictPolicy::REPLACE_EXISTING, ConflictPolicy::fromRequest('replace_existing'));
    }

    public function test_a_day_with_no_information_is_never_a_conflict(): void
    {
        $draft = ScheduleDraft::of([ScheduleDraftEntry::work(WorkDate::fromString(self::DATE), [$this->morningProposal()])], RosterSource::MANUAL);

        $resolved = (new ScheduleDraftResolver())->resolve($draft, [], ConflictPolicy::SKIP_EXISTING);

        self::assertSame(0, $resolved->conflictCount());
        self::assertSame(1, $resolved->affectedDays());
    }

    /**
     * Proposing exactly what is already stored changes nothing, so it must not
     * be counted as a day modified — "12 días modificados" has to be true.
     */
    public function test_proposing_what_is_already_there_is_neither_a_conflict_nor_a_write(): void
    {
        $draft = ScheduleDraft::of([ScheduleDraftEntry::work(WorkDate::fromString(self::DATE), [$this->morningProposal()])], RosterSource::MANUAL);

        $resolved = (new ScheduleDraftResolver())->resolve($draft, [$this->existingMorning()], ConflictPolicy::SKIP_EXISTING);

        self::assertSame(0, $resolved->conflictCount());
        self::assertSame(0, $resolved->affectedDays());
    }

    public function test_clearing_a_day_is_a_deletion_not_a_conflict(): void
    {
        $draft = ScheduleDraft::of([ScheduleDraftEntry::clear(WorkDate::fromString(self::DATE))], RosterSource::MANUAL);

        $resolved = (new ScheduleDraftResolver())->resolve($draft, [$this->existingNight()], ConflictPolicy::SKIP_EXISTING);

        self::assertSame(0, $resolved->conflictCount());
        self::assertSame(1, $resolved->affectedDays());
    }

    public function test_clearing_a_day_that_is_already_unknown_writes_nothing(): void
    {
        $draft = ScheduleDraft::of([ScheduleDraftEntry::clear(WorkDate::fromString(self::DATE))], RosterSource::MANUAL);

        $resolved = (new ScheduleDraftResolver())->resolve($draft, [], ConflictPolicy::SKIP_EXISTING);

        self::assertSame(0, $resolved->affectedDays());
    }

    public function test_an_unresolved_entry_never_reaches_the_writer(): void
    {
        $draft = ScheduleDraft::of([ScheduleDraftEntry::unresolved(WorkDate::fromString(self::DATE), 'Ese turno ya no existe.')], RosterSource::PATTERN);

        $resolved = (new ScheduleDraftResolver())->resolve($draft, [], ConflictPolicy::REPLACE_EXISTING);

        self::assertTrue($resolved->isEmpty());
        self::assertSame(0, $resolved->affectedDays());
    }

    private function resolve(ConflictPolicy $policy): \App\Scheduling\Domain\ResolvedScheduleDraft
    {
        $draft = ScheduleDraft::of([ScheduleDraftEntry::work(WorkDate::fromString(self::DATE), [$this->morningProposal()])], RosterSource::MANUAL);

        return (new ScheduleDraftResolver())->resolve($draft, [$this->existingNight()], $policy);
    }

    private function morningProposal(): SegmentProposal
    {
        return new SegmentProposal('morning', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING);
    }

    private function existingNight(): RosterDay
    {
        $segment = new ShiftSegment('s1', 'night', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, 0);

        return RosterDay::working('d1', 'a1', WorkDate::fromString(self::DATE), [$segment], RosterSource::MANUAL, $this->now());
    }

    private function existingMorning(): RosterDay
    {
        $segment = new ShiftSegment('s1', 'morning', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, 0);

        return RosterDay::working('d1', 'a1', WorkDate::fromString(self::DATE), [$segment], RosterSource::MANUAL, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01T09:00:00+00:00');
    }
}
