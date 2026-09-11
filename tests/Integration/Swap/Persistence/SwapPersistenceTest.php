<?php

declare(strict_types=1);

namespace App\Tests\Integration\Swap\Persistence;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\Availability;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The real SQL against the schema the real migrations build.
 *
 * Two things are worth proving here rather than in a unit test: that PostgreSQL
 * itself refuses the duplicates a double tap would create, and that the
 * discovery queries are scoped by pool and date the way the indexes assume.
 */
final class SwapPersistenceTest extends KernelTestCase
{
    private const TODAY = '2026-09-15';

    private const DATE = '2026-09-18';

    private Connection $connection;

    private SwapRequests $requests;

    private Availabilities $availabilities;

    private string $poolUci;

    private string $poolPorters;

    private string $pedro;

    private string $pedroAssignment;

    private string $maria;

    private string $mariaAssignment;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $connection = $container->get(Connection::class);
        $requests = $container->get(SwapRequests::class);
        $availabilities = $container->get(Availabilities::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SwapRequests::class, $requests);
        self::assertInstanceOf(Availabilities::class, $availabilities);

        $this->connection = $connection;
        $this->requests = $requests;
        $this->availabilities = $availabilities;
        $this->connection->beginTransaction();

        $workplace = $this->seedWorkplace();
        $this->poolUci = $this->seedPool($workplace, 'uci');
        $this->poolPorters = $this->seedPool($workplace, 'porters');
        [$this->pedro, $this->pedroAssignment] = $this->seedWorker($workplace);
        [$this->maria, $this->mariaAssignment] = $this->seedWorker($workplace);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function test_a_published_shift_round_trips(): void
    {
        $this->requests->save($this->request($this->pedro, $this->pedroAssignment, $this->poolUci));

        $stored = $this->requests->openFor($this->pedroAssignment, WorkDate::fromString(self::DATE));

        self::assertNotNull($stored);
        self::assertSame($this->poolUci, $stored->swapPoolId());
        self::assertTrue($stored->isOpen());
    }

    /** The application checks first; the database is what survives a race. */
    public function test_postgresql_refuses_a_second_open_request_for_the_same_shift(): void
    {
        $this->requests->save($this->request($this->pedro, $this->pedroAssignment, $this->poolUci));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->requests->save($this->request($this->pedro, $this->pedroAssignment, $this->poolUci));
    }

    /** Withdrawing frees the day: the partial index only covers open rows. */
    public function test_a_withdrawn_shift_can_be_published_again(): void
    {
        $first = $this->request($this->pedro, $this->pedroAssignment, $this->poolUci);
        $this->requests->save($first);
        $first->cancel($this->pedro, new DateTimeImmutable(self::TODAY));
        $this->requests->save($first);

        $second = $this->request($this->pedro, $this->pedroAssignment, $this->poolUci);
        $this->requests->save($second);

        self::assertSame($second->id(), $this->requests->openFor($this->pedroAssignment, WorkDate::fromString(self::DATE))?->id());
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM swap_requests WHERE worker_id = :worker', ['worker' => $this->pedro]));
    }

    public function test_discovery_is_scoped_to_the_pool_and_excludes_the_author(): void
    {
        $pedroNight = $this->request($this->pedro, $this->pedroAssignment, $this->poolUci);
        $this->requests->save($pedroNight);
        $this->requests->save($this->request($this->maria, $this->mariaAssignment, $this->poolPorters));

        $from = WorkDate::fromString(self::TODAY);

        $forMaria = $this->requests->openInPools([$this->poolUci], $from, $this->maria);
        self::assertSame([$pedroNight->id()], array_map(static fn (SwapRequest $request): string => $request->id(), $forMaria));

        $forPedro = $this->requests->openInPools([$this->poolUci], $from, $this->pedro);
        self::assertSame([], $forPedro, 'Nobody is offered their own shift.');
    }

    public function test_an_availability_is_one_row_per_exact_worker_pool_day_and_kind_slot(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->availabilities->save($this->availability($this->maria, $this->mariaAssignment, $this->poolUci, true));
        }

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->maria]));
        self::assertCount(1, $this->availabilities->activeByWorker($this->maria, WorkDate::fromString(self::TODAY)), 'The read query must not multiply a slot through joins.');
    }

    public function test_withdrawing_keeps_the_row_and_hides_it_from_the_pool(): void
    {
        $this->availabilities->save($this->availability($this->maria, $this->mariaAssignment, $this->poolUci, true));
        $this->availabilities->save($this->availability($this->maria, $this->mariaAssignment, $this->poolUci, false));

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->maria]));
        self::assertSame([], $this->availabilities->activeInPoolOnDate($this->poolUci, WorkDate::fromString(self::DATE), ShiftKind::NIGHT, $this->pedro));
    }

    public function test_candidate_counts_are_matched_by_pool_and_day(): void
    {
        $request = $this->request($this->pedro, $this->pedroAssignment, $this->poolUci);
        $this->requests->save($request);
        $this->availabilities->save($this->availability($this->maria, $this->mariaAssignment, $this->poolUci, true));
        // Same day, another group: not a candidate for this shift.
        $this->availabilities->save($this->availability($this->maria, $this->mariaAssignment, $this->poolPorters, true));

        self::assertSame([$request->id() => 1], $this->requests->candidateCounts([$request->id()]));
    }

    public function test_candidate_counts_also_require_the_same_shift_kind(): void
    {
        $request = $this->request($this->pedro, $this->pedroAssignment, $this->poolUci);
        $this->requests->save($request);
        $morning = Availability::declare(
            Uuid::v7()->toRfc4122(),
            $this->maria,
            $this->mariaAssignment,
            $this->poolUci,
            WorkDate::fromString(self::DATE),
            ShiftKind::MORNING,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable(self::TODAY),
        );
        $this->availabilities->save($morning);

        self::assertSame([$request->id() => 0], $this->requests->candidateCounts([$request->id()]));

        $this->availabilities->save($this->availability($this->maria, $this->mariaAssignment, $this->poolUci, true));
        self::assertSame([$request->id() => 1], $this->requests->candidateCounts([$request->id()]));
    }

    public function test_a_worker_never_counts_as_a_candidate_for_their_own_shift(): void
    {
        $request = $this->request($this->pedro, $this->pedroAssignment, $this->poolUci);
        $this->requests->save($request);
        $this->availabilities->save($this->availability($this->pedro, $this->pedroAssignment, $this->poolUci, true));

        self::assertSame([$request->id() => 0], $this->requests->candidateCounts([$request->id()]));
    }

    private function request(string $workerId, string $assignmentId, string $poolId): SwapRequest
    {
        return SwapRequest::open(
            Uuid::v7()->toRfc4122(),
            $workerId,
            $assignmentId,
            $poolId,
            Uuid::v7()->toRfc4122(),
            WorkDate::fromString(self::DATE),
            ShiftKind::NIGHT,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable(self::TODAY),
        );
    }

    private function availability(string $workerId, string $assignmentId, string $poolId, bool $active): Availability
    {
        $availability = Availability::declare(
            Uuid::v7()->toRfc4122(),
            $workerId,
            $assignmentId,
            $poolId,
            WorkDate::fromString(self::DATE),
            ShiftKind::NIGHT,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable(self::TODAY),
        );
        if (!$active) {
            $availability->withdraw($workerId, new DateTimeImmutable(self::TODAY));
        }

        return $availability;
    }

    private function seedWorkplace(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_workplaces', [
            'id' => $id,
            'source' => 'ministry_hospitals',
            'external_id' => 'swap-'.$id,
            'name' => 'Hospital de pruebas',
            'type' => 'hospital',
            'autonomous_community' => 'Región de Murcia',
            'province' => 'Murcia',
            'municipality' => 'Murcia',
            'ownership' => 'public',
            'active' => true,
            'source_updated_at' => '2026-09-10T00:00:00+00:00',
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);

        return $id;
    }

    private function seedPool(string $workplaceId, string $discriminator): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_swap_pools', [
            'id' => $id,
            'workplace_id' => $workplaceId,
            'staff_category_id' => $this->anyCategory(),
            'specialty_id' => null,
            'organizational_unit_id' => null,
            'functional_area' => $discriminator,
            'employer_id' => null,
            'fingerprint' => $workplaceId.'|'.$discriminator,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);

        return $id;
    }

    /** @return array{string, string} worker id and assignment id */
    private function seedWorker(string $workplaceId): array
    {
        $userId = Uuid::v7()->toRfc4122();
        $assignmentId = Uuid::v7()->toRfc4122();
        $this->connection->insert('identity_users', [
            'id' => $userId,
            'email' => $userId.'@example.test',
            'password_hash' => null,
            'has_worker_profile' => true,
            'has_supervisor_profile' => false,
            'created_at' => '2026-09-10T00:00:00+00:00',
        ], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);
        $this->connection->insert('workforce_worker_assignments', [
            'id' => $assignmentId,
            'worker_id' => $userId,
            'workplace_id' => $workplaceId,
            'staff_category_id' => $this->anyCategory(),
            'specialty_id' => null,
            'organizational_unit_id' => null,
            'functional_area' => null,
            'employer_id' => null,
            'primary_assignment' => true,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['primary_assignment' => 'boolean', 'active' => 'boolean']);

        return [$userId, $assignmentId];
    }

    private function anyCategory(): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM workforce_staff_categories LIMIT 1');
        self::assertIsString($id, 'The staff category catalogue is seeded by migrations.');

        return $id;
    }

    /** @param array<string, mixed> $parameters */
    private function countRows(string $sql, array $parameters): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
