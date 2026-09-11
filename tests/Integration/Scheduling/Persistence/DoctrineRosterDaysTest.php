<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scheduling\Persistence;

use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The real SQL against the real schema, built by the real migrations.
 *
 * Two things are worth proving here rather than in a unit test: that a day
 * survives the round trip with its segments and its wall-clock hours intact,
 * and that PostgreSQL itself refuses a second row for the same worker and day.
 */
final class DoctrineRosterDaysTest extends KernelTestCase
{
    private Connection $connection;

    private RosterDays $days;

    private ShiftPresets $presets;

    private string $assignmentId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $connection = $container->get(Connection::class);
        $days = $container->get(RosterDays::class);
        $presets = $container->get(ShiftPresets::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(RosterDays::class, $days);
        self::assertInstanceOf(ShiftPresets::class, $presets);

        $this->connection = $connection;
        $this->days = $days;
        $this->presets = $presets;
        $this->connection->beginTransaction();
        $this->assignmentId = $this->seedAssignment();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function test_a_working_day_survives_the_round_trip_with_its_snapshot(): void
    {
        $preset = $this->preset('Noche', 'N', '22:00', '08:00', ShiftKind::NIGHT);
        $segment = ShiftSegment::fromPreset(Uuid::v7()->toRfc4122(), $preset, 0);
        $day = RosterDay::working(Uuid::v7()->toRfc4122(), $this->assignmentId, WorkDate::fromString('2026-10-24'), [$segment], RosterSource::PATTERN, $this->now());

        $this->days->apply($this->assignmentId, [$day], []);
        $stored = $this->days->onDate($this->assignmentId, WorkDate::fromString('2026-10-24'));

        self::assertNotNull($stored);
        self::assertTrue($stored->isWorking());
        self::assertSame(RosterSource::PATTERN, $stored->source());
        self::assertCount(1, $stored->segments());
        self::assertSame('Noche', $stored->segments()[0]->labelSnapshot);
        self::assertSame('22:00', (string) $stored->segments()[0]->window->start);
        self::assertSame('08:00', (string) $stored->segments()[0]->window->end);
        self::assertTrue($stored->segments()[0]->endsNextDay());
        self::assertSame($preset->id(), $stored->segments()[0]->presetId);
    }

    public function test_a_rest_day_stores_no_segments(): void
    {
        $day = RosterDay::rest(Uuid::v7()->toRfc4122(), $this->assignmentId, WorkDate::fromString('2026-10-25'), RosterSource::MANUAL, $this->now());
        $this->days->apply($this->assignmentId, [$day], []);

        $stored = $this->days->onDate($this->assignmentId, WorkDate::fromString('2026-10-25'));

        self::assertNotNull($stored);
        self::assertTrue($stored->isRest());
        self::assertSame([], $stored->segments());
    }

    public function test_a_split_shift_keeps_its_segments_in_order(): void
    {
        $morning = ShiftSegment::fromPreset(Uuid::v7()->toRfc4122(), $this->preset('Mañana', 'M', '08:00', '12:00', ShiftKind::MORNING), 0);
        $evening = ShiftSegment::fromPreset(Uuid::v7()->toRfc4122(), $this->preset('Tarde', 'T', '16:00', '20:00', ShiftKind::EVENING), 1);
        $day = RosterDay::working(Uuid::v7()->toRfc4122(), $this->assignmentId, WorkDate::fromString('2026-10-26'), [$morning, $evening], RosterSource::MANUAL, $this->now());

        $this->days->apply($this->assignmentId, [$day], []);
        $stored = $this->days->onDate($this->assignmentId, WorkDate::fromString('2026-10-26'));

        self::assertNotNull($stored);
        self::assertSame(['M', 'T'], array_map(static fn (ShiftSegment $segment): string => $segment->abbreviationSnapshot, $stored->segments()));
    }

    public function test_applying_the_same_day_again_replaces_it_rather_than_duplicating_it(): void
    {
        $preset = $this->preset('Mañana', 'M', '08:00', '15:00', ShiftKind::MORNING);
        $date = WorkDate::fromString('2026-10-27');

        $this->days->apply($this->assignmentId, [RosterDay::working(Uuid::v7()->toRfc4122(), $this->assignmentId, $date, [ShiftSegment::fromPreset(Uuid::v7()->toRfc4122(), $preset, 0)], RosterSource::MANUAL, $this->now())], []);
        $this->days->apply($this->assignmentId, [RosterDay::rest(Uuid::v7()->toRfc4122(), $this->assignmentId, $date, RosterSource::VOICE, $this->now())], []);

        self::assertSame(1, $this->days->countFor($this->assignmentId));
        self::assertTrue($this->days->onDate($this->assignmentId, $date)?->isRest());
    }

    /** The database is the last guard, not the application. */
    public function test_postgresql_refuses_two_rows_for_the_same_worker_and_day(): void
    {
        $this->connection->insert('scheduling_roster_days', [
            'id' => Uuid::v7()->toRfc4122(),
            'worker_assignment_id' => $this->assignmentId,
            'work_date' => '2026-10-28',
            'state' => 'rest',
            'source' => 'manual',
            'created_at' => '2026-09-11T09:00:00+00:00',
            'updated_at' => '2026-09-11T09:00:00+00:00',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('scheduling_roster_days', [
            'id' => Uuid::v7()->toRfc4122(),
            'worker_assignment_id' => $this->assignmentId,
            'work_date' => '2026-10-28',
            'state' => 'working',
            'source' => 'manual',
            'created_at' => '2026-09-11T09:00:00+00:00',
            'updated_at' => '2026-09-11T09:00:00+00:00',
        ]);
    }

    public function test_clearing_a_day_removes_it_and_its_segments(): void
    {
        $preset = $this->preset('Mañana', 'M', '08:00', '15:00', ShiftKind::MORNING);
        $date = WorkDate::fromString('2026-10-29');
        $dayId = Uuid::v7()->toRfc4122();
        $this->days->apply($this->assignmentId, [RosterDay::working($dayId, $this->assignmentId, $date, [ShiftSegment::fromPreset(Uuid::v7()->toRfc4122(), $preset, 0)], RosterSource::MANUAL, $this->now())], []);

        $this->days->apply($this->assignmentId, [], [$date]);

        self::assertNull($this->days->onDate($this->assignmentId, $date));
        $remainingSegments = $this->connection->fetchOne('SELECT COUNT(*) FROM scheduling_roster_segments WHERE roster_day_id = :day', ['day' => $dayId]);
        self::assertIsNumeric($remainingSegments);
        self::assertSame(0, (int) $remainingSegments, 'Deleting the day must take its segments with it.');
    }

    public function test_a_quarter_of_rotation_is_one_range_query(): void
    {
        $preset = $this->preset('Mañana', 'M', '08:00', '15:00', ShiftKind::MORNING);
        $from = WorkDate::fromString('2026-09-14');
        $days = [];
        for ($offset = 0; $offset < 108; ++$offset) {
            $date = $from->plusDays($offset);
            $days[] = 0 === $offset % 3
                ? RosterDay::rest(Uuid::v7()->toRfc4122(), $this->assignmentId, $date, RosterSource::PATTERN, $this->now())
                : RosterDay::working(Uuid::v7()->toRfc4122(), $this->assignmentId, $date, [ShiftSegment::fromPreset(Uuid::v7()->toRfc4122(), $preset, 0)], RosterSource::PATTERN, $this->now());
        }

        $this->days->apply($this->assignmentId, $days, []);

        self::assertSame(108, $this->days->countFor($this->assignmentId));
        // A month view reads one range, not one query per day.
        self::assertCount(31, $this->days->inRange($this->assignmentId, WorkDate::fromString('2026-10-01'), WorkDate::fromString('2026-10-31')));
    }

    public function test_a_roster_is_scoped_to_its_own_assignment(): void
    {
        $other = $this->seedAssignment();
        $this->days->apply($this->assignmentId, [RosterDay::rest(Uuid::v7()->toRfc4122(), $this->assignmentId, WorkDate::fromString('2026-11-01'), RosterSource::MANUAL, $this->now())], []);

        self::assertNull($this->days->onDate($other, WorkDate::fromString('2026-11-01')));
        self::assertSame(0, $this->days->countFor($other));
    }

    private function preset(string $name, string $abbreviation, string $start, string $end, ShiftKind $kind): ShiftPreset
    {
        $preset = ShiftPreset::create(Uuid::v7()->toRfc4122(), $this->assignmentId, $name, $abbreviation, ShiftWindow::fromStrings($start, $end), $kind, [], 1, $this->now());
        $this->presets->save($preset);

        return $preset;
    }

    /** A roster hangs off a worker assignment, so the fixture needs the real chain. */
    private function seedAssignment(): string
    {
        $userId = Uuid::v7()->toRfc4122();
        $workplaceId = Uuid::v7()->toRfc4122();
        $assignmentId = Uuid::v7()->toRfc4122();

        $this->connection->insert('identity_users', [
            'id' => $userId,
            'email' => $userId.'@example.test',
            'password_hash' => null,
            'has_worker_profile' => true,
            'has_supervisor_profile' => false,
            'created_at' => '2026-09-10T00:00:00+00:00',
        ], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);

        $this->connection->insert('workforce_workplaces', [
            'id' => $workplaceId,
            'source' => 'ministry_hospitals',
            'external_id' => 'roster-'.$assignmentId,
            'name' => 'Hospital de pruebas',
            'type' => 'hospital',
            'autonomous_community' => 'CANARIAS',
            'province' => 'Las Palmas',
            'municipality' => 'Las Palmas de Gran Canaria',
            'ownership' => 'public',
            'active' => true,
            'source_updated_at' => '2026-09-10T00:00:00+00:00',
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);

        $this->connection->insert('workforce_worker_assignments', [
            'id' => $assignmentId,
            'worker_id' => $userId,
            'workplace_id' => $workplaceId,
            'staff_category_id' => $this->anyStaffCategoryId(),
            'specialty_id' => null,
            'organizational_unit_id' => null,
            'functional_area' => null,
            'employer_id' => null,
            'primary_assignment' => true,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['primary_assignment' => 'boolean', 'active' => 'boolean']);

        return $assignmentId;
    }

    private function anyStaffCategoryId(): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM workforce_staff_categories LIMIT 1');
        if (\is_string($id)) {
            return $id;
        }

        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_staff_categories', [
            'id' => $id,
            'code' => 'test-category-'.$id,
            'name' => 'Enfermería',
            'aliases' => '[]',
            'requires_specialty' => false,
            'requires_functional_area' => false,
            'display_order' => 1,
            'active' => true,
        ], ['requires_specialty' => 'boolean', 'requires_functional_area' => 'boolean', 'active' => 'boolean']);

        return $id;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11T09:00:00+00:00');
    }
}
