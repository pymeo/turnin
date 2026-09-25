<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workforce\Persistence;

use App\Tests\Support\Swap\SwapWorldSeed;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorVerification;
use App\Workforce\Domain\Supervision\SupervisorVerificationDecision;
use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SupervisorVerificationSource;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The database is the last line against a double tap: whatever the code does,
 * PostgreSQL refuses a second vote from the same colleague and a second active
 * assignment for the same person and pool.
 */
final class SupervisorPersistenceTest extends KernelTestCase
{
    private Connection $connection;

    private SupervisorAssignments $assignments;

    private SwapPoolTeams $teams;

    private string $pool;

    /** @var array<string, array{id: string, assignment: string, email: string}> */
    private array $people = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        $assignments = static::getContainer()->get(SupervisorAssignments::class);
        $teams = static::getContainer()->get(SwapPoolTeams::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SupervisorAssignments::class, $assignments);
        self::assertInstanceOf(SwapPoolTeams::class, $teams);
        $this->connection = $connection;
        $this->assignments = $assignments;
        $this->teams = $teams;
        $this->connection->beginTransaction();

        $seed = new SwapWorldSeed($this->connection);
        $this->pool = $seed->pool('UCI');
        foreach (['laura' => 'Laura', 'ana' => 'Ana', 'david' => 'David'] as $key => $name) {
            $this->people[$key] = $seed->worker($name, $this->pool);
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function test_the_team_is_read_from_active_memberships_and_the_pool_is_described(): void
    {
        $team = $this->teams->team($this->pool);

        self::assertTrue($team->includes($this->people['ana']['id']));
        self::assertFalse($team->includes(Uuid::v7()->toRfc4122()));
        self::assertStringStartsWith('UCI · ', $this->teams->describe($this->pool)->teamLabel ?? '');
        self::assertContains($this->pool, $this->teams->poolsOf($this->people['ana']['id']));

        $this->connection->executeStatement('UPDATE workforce_swap_pool_memberships SET active = FALSE WHERE worker_id = :ana', ['ana' => $this->people['ana']['id']]);
        self::assertFalse($this->teams->team($this->pool)->includes($this->people['ana']['id']), 'A deactivated membership cannot vouch.');
    }

    public function test_a_verified_assignment_round_trips_with_its_verifications(): void
    {
        $assignment = $this->assignment();
        $team = $this->teams->team($this->pool);
        $assignment->recordVerification($this->vote('ana', SupervisorVerificationDecision::CONFIRMED, SupervisorVerificationSource::INVITATION), $team, new SupervisorVerificationPolicy());
        $assignment->recordVerification($this->vote('david', SupervisorVerificationDecision::CANNOT_CONFIRM), $team, new SupervisorVerificationPolicy());
        $this->assignments->save($assignment);
        $assignment->recordVerification($this->vote('david', SupervisorVerificationDecision::CONFIRMED), $team, new SupervisorVerificationPolicy());
        $this->assignments->save($assignment);

        $loaded = $this->assignments->byId($assignment->id());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->hasApprovalAuthority());
        self::assertSame(2, $loaded->confirmations());
        self::assertCount(2, $loaded->verifications());
        self::assertSame($loaded->id(), $this->assignments->activeFor($this->people['laura']['id'], $this->pool)?->id());
    }

    public function test_postgres_refuses_a_second_vote_from_the_same_colleague(): void
    {
        $assignment = $this->assignment();
        $this->assignments->save($assignment);
        $row = ['supervisor_assignment_id' => $assignment->id(), 'verifier_worker_id' => $this->people['ana']['id'], 'decision' => 'confirmed', 'source' => 'team_member', 'created_at' => '2026-09-25T10:00:00+00:00'];
        $this->connection->insert('workforce_supervisor_verifications', ['id' => Uuid::v7()->toRfc4122()] + $row);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('workforce_supervisor_verifications', ['id' => Uuid::v7()->toRfc4122()] + $row);
    }

    public function test_postgres_refuses_two_active_assignments_for_the_same_person_and_pool(): void
    {
        $this->assignments->save($this->assignment());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->assignments->save($this->assignment());
    }

    public function test_leaving_frees_the_slot_without_deleting_the_row(): void
    {
        $first = $this->assignment();
        $this->assignments->save($first);
        $first->leave($this->people['laura']['id'], new DateTimeImmutable('2026-09-26T10:00:00+00:00'));
        $this->assignments->save($first);
        $this->assignments->save($this->assignment());

        self::assertEquals(2, $this->connection->fetchOne('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura', ['laura' => $this->people['laura']['id']]));
    }

    private function assignment(): SupervisorAssignment
    {
        $id = Uuid::v7()->toRfc4122();

        return SupervisorAssignment::requestVerification($id, $this->people['laura']['id'], $this->pool, null, hash('sha256', $id), new DateTimeImmutable('2026-09-25T10:00:00+00:00'));
    }

    private function vote(string $who, SupervisorVerificationDecision $decision, SupervisorVerificationSource $source = SupervisorVerificationSource::TEAM_MEMBER): SupervisorVerification
    {
        return new SupervisorVerification(Uuid::v7()->toRfc4122(), $this->people[$who]['id'], $decision, $source, new DateTimeImmutable('2026-09-25T11:00:00+00:00'));
    }
}
