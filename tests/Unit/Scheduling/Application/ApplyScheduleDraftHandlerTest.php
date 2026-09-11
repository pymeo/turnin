<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\Command\ApplyScheduleDraft;
use App\Scheduling\Application\Command\ApplyScheduleDraftHandler;
use App\Scheduling\Application\RosterAccessDenied;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\DraftInstruction;
use App\Scheduling\Domain\DraftIntent;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraftComposer;
use App\Scheduling\Domain\ScheduleDraftResolver;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use App\Tests\Support\Scheduling\FixedAssignedWorkers;
use App\Tests\Support\Scheduling\FrozenClock;
use App\Tests\Support\Scheduling\InMemoryRosterDays;
use App\Tests\Support\Scheduling\InMemoryShiftPresets;
use App\Tests\Support\Scheduling\SequentialRosterIds;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The single writer. Everything about ownership, conflicts and "one operation,
 * not a hundred" is decided here.
 */
final class ApplyScheduleDraftHandlerTest extends TestCase
{
    private InMemoryRosterDays $days;

    private InMemoryShiftPresets $presets;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-11T09:00:00+00:00');
        $this->days = new InMemoryRosterDays();
        $this->presets = new InMemoryShiftPresets([
            ShiftPreset::create('morning', 'assignment-1', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, [], 1, $now),
            ShiftPreset::create('night', 'assignment-1', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, [], 2, $now),
            ShiftPreset::create('someone-elses', 'assignment-9', 'Refuerzo', 'R', ShiftWindow::fromStrings('10:00', '14:00'), ShiftKind::OTHER, [], 1, $now),
        ]);
    }

    public function test_it_writes_a_whole_month_in_one_operation(): void
    {
        $instructions = [];
        for ($day = 1; $day <= 30; ++$day) {
            $instructions[] = new DraftInstruction(WorkDate::of(2026, 9, $day), DraftIntent::WORK, ['morning']);
        }

        $applied = ($this->handler())(new ApplyScheduleDraft('worker-1', $instructions, RosterSource::MANUAL));

        self::assertSame(30, $applied->writtenDays);
        self::assertSame(1, $this->days->applyCalls, 'A month is one write, not thirty.');
        self::assertSame(30, $this->days->countFor('assignment-1'));
    }

    public function test_it_keeps_existing_days_by_default(): void
    {
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 5), DraftIntent::WORK, ['night'])], RosterSource::MANUAL));

        $applied = ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 5), DraftIntent::WORK, ['morning'])], RosterSource::VOICE));

        self::assertSame(0, $applied->writtenDays);
        self::assertSame(1, $applied->skippedConflicts);
        self::assertSame('N', $this->days->onDate('assignment-1', WorkDate::of(2026, 9, 5))?->abbreviation());
    }

    public function test_it_replaces_only_when_the_worker_asked_for_it(): void
    {
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 5), DraftIntent::WORK, ['night'])], RosterSource::MANUAL));

        $applied = ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 5), DraftIntent::WORK, ['morning'])], RosterSource::VOICE, ConflictPolicy::REPLACE_EXISTING));

        self::assertSame(1, $applied->writtenDays);
        self::assertSame('M', $this->days->onDate('assignment-1', WorkDate::of(2026, 9, 5))?->abbreviation());
    }

    public function test_clearing_a_day_returns_it_to_unknown(): void
    {
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 5), DraftIntent::REST)], RosterSource::MANUAL));
        self::assertNotNull($this->days->onDate('assignment-1', WorkDate::of(2026, 9, 5)));

        $applied = ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 5), DraftIntent::CLEAR)], RosterSource::MANUAL));

        self::assertSame(1, $applied->clearedDays);
        self::assertNull($this->days->onDate('assignment-1', WorkDate::of(2026, 9, 5)), 'Clearing is not the same as marking a rest day.');
    }

    public function test_a_rest_day_is_not_the_same_as_a_cleared_day(): void
    {
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 6), DraftIntent::REST)], RosterSource::MANUAL));

        $day = $this->days->onDate('assignment-1', WorkDate::of(2026, 9, 6));

        self::assertNotNull($day);
        self::assertTrue($day->isRest());
        self::assertSame('L', $day->abbreviation());
    }

    /** Ownership is resolved server side; an id from another account resolves to nothing. */
    public function test_a_preset_belonging_to_someone_else_is_never_applied(): void
    {
        $applied = ($this->handler())(new ApplyScheduleDraft('worker-1', [
            new DraftInstruction(WorkDate::of(2026, 9, 7), DraftIntent::WORK, ['someone-elses']),
            new DraftInstruction(WorkDate::of(2026, 9, 8), DraftIntent::WORK, ['morning']),
        ], RosterSource::MANUAL));

        self::assertSame(1, $applied->writtenDays);
        self::assertNull($this->days->onDate('assignment-1', WorkDate::of(2026, 9, 7)));
    }

    public function test_the_segment_stores_a_snapshot_of_the_preset(): void
    {
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 9), DraftIntent::WORK, ['night'])], RosterSource::MANUAL));

        $segment = $this->days->onDate('assignment-1', WorkDate::of(2026, 9, 9))?->firstSegment();

        self::assertNotNull($segment);
        self::assertSame('Noche', $segment->labelSnapshot);
        self::assertSame('22:00', (string) $segment->window->start);
        self::assertSame('night', $segment->presetId);
        self::assertTrue($segment->endsNextDay());
    }

    public function test_the_source_of_each_day_is_recorded(): void
    {
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 10), DraftIntent::WORK, ['morning'])], RosterSource::VOICE));

        self::assertSame(RosterSource::VOICE, $this->days->onDate('assignment-1', WorkDate::of(2026, 9, 10))?->source());
    }

    public function test_an_account_without_a_worker_assignment_cannot_write_a_roster(): void
    {
        $handler = new ApplyScheduleDraftHandler(
            new RosterWorkspace(FixedAssignedWorkers::withoutAssignment(), $this->presets),
            $this->days,
            new ScheduleDraftComposer(),
            new ScheduleDraftResolver(),
            new SequentialRosterIds(),
            new FrozenClock('2026-09-11T09:00:00+00:00'),
        );

        $this->expectException(RosterAccessDenied::class);
        $handler(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 1), DraftIntent::REST)], RosterSource::MANUAL));
    }

    public function test_an_empty_draft_is_refused_rather_than_silently_accepted(): void
    {
        $this->expectException(RuntimeException::class);
        ($this->handler())(new ApplyScheduleDraft('worker-1', [], RosterSource::MANUAL));
    }

    public function test_an_assignment_identifier_owned_by_another_worker_is_rejected(): void
    {
        $this->expectException(RosterAccessDenied::class);
        ($this->handler())(new ApplyScheduleDraft('worker-1', [new DraftInstruction(WorkDate::of(2026, 9, 1), DraftIntent::REST)], RosterSource::MANUAL, ConflictPolicy::SKIP_EXISTING, 'assignment-of-worker-2'));
    }

    private function handler(): ApplyScheduleDraftHandler
    {
        return new ApplyScheduleDraftHandler(
            new RosterWorkspace(FixedAssignedWorkers::inMadrid(), $this->presets),
            $this->days,
            new ScheduleDraftComposer(),
            new ScheduleDraftResolver(),
            new SequentialRosterIds(),
            new FrozenClock('2026-09-11T09:00:00+00:00'),
        );
    }
}
