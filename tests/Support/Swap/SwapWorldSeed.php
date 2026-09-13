<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A centre, two swap pools, some colleagues and their rotas, written straight
 * into the database.
 *
 * Functional tests about *choosing* a shift need a rota with shape — days on,
 * days off, a rest block — and building that through the interface would spend
 * a minute of browser time on setup that is not what the test is about. What
 * the tests then exercise is still the real HTTP surface.
 */
final class SwapWorldSeed
{
    /** @var list<string> */
    private array $userIds = [];

    private string $workplaceId = '';

    /** @var list<string> */
    private array $poolIds = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function workplace(): string
    {
        if ('' !== $this->workplaceId) {
            return $this->workplaceId;
        }

        $this->workplaceId = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_workplaces', [
            'id' => $this->workplaceId,
            'source' => 'ministry_hospitals',
            'external_id' => 'composer-'.$this->workplaceId,
            'name' => 'Hospital de Pruebas',
            'type' => 'hospital',
            'autonomous_community' => 'Andalucía',
            'province' => 'Granada',
            'municipality' => 'Granada',
            'ownership' => 'public',
            'active' => true,
            'source_updated_at' => '2026-09-10T00:00:00+00:00',
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);

        return $this->workplaceId;
    }

    public function pool(string $area): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_swap_pools', [
            'id' => $id,
            'workplace_id' => $this->workplace(),
            'staff_category_id' => $this->anyCategory(),
            'specialty_id' => null,
            'organizational_unit_id' => null,
            'functional_area' => $area,
            'employer_id' => null,
            'fingerprint' => $this->workplace().'|'.$area.'|'.$id,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);
        $this->poolIds[] = $id;

        return $id;
    }

    /** @return array{id: string, assignment: string, email: string} */
    public function worker(string $givenName, string $poolId): array
    {
        $userId = Uuid::v7()->toRfc4122();
        $assignmentId = Uuid::v7()->toRfc4122();
        $email = $userId.'@example.test';
        $this->userIds[] = $userId;

        $this->connection->insert('identity_users', [
            'id' => $userId,
            'email' => $email,
            'password_hash' => null,
            'has_worker_profile' => true,
            'has_supervisor_profile' => false,
            'created_at' => '2026-09-10T00:00:00+00:00',
        ], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);

        $this->connection->insert('identity_personal_profiles', [
            'user_id' => $userId,
            'given_name' => $givenName,
            'family_name' => 'de Prueba',
            'phone_encrypted' => null,
            'identity_evidence' => null,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ]);

        $this->connection->insert('workforce_worker_assignments', [
            'id' => $assignmentId,
            'worker_id' => $userId,
            'workplace_id' => $this->workplace(),
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

        $this->connection->insert('workforce_swap_pool_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'swap_pool_id' => $poolId,
            'worker_id' => $userId,
            'assignment_id' => $assignmentId,
            'source' => 'self_declared',
            'is_primary' => true,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['is_primary' => 'boolean', 'active' => 'boolean']);

        return ['id' => $userId, 'assignment' => $assignmentId, 'email' => $email];
    }

    public function shift(string $assignmentId, string $date, string $label = 'Mañana', string $abbreviation = 'M', string $startsAt = '08:00', string $endsAt = '15:00', string $kind = 'morning'): void
    {
        $dayId = Uuid::v7()->toRfc4122();
        $this->connection->insert('scheduling_roster_days', [
            'id' => $dayId,
            'worker_assignment_id' => $assignmentId,
            'work_date' => $date,
            'state' => 'working',
            'source' => 'manual',
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ]);
        $this->connection->insert('scheduling_roster_segments', [
            'id' => Uuid::v7()->toRfc4122(),
            'roster_day_id' => $dayId,
            'shift_preset_id' => null,
            'label_snapshot' => $label,
            'abbreviation_snapshot' => $abbreviation,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'kind' => $kind,
            'position' => 0,
            'color_key_snapshot' => 'blue',
        ]);
    }

    /** A declared day off. Deliberately a row: "libre" is not the absence of one. */
    public function restDay(string $assignmentId, string $date): void
    {
        $this->connection->insert('scheduling_roster_days', [
            'id' => Uuid::v7()->toRfc4122(),
            'worker_assignment_id' => $assignmentId,
            'work_date' => $date,
            'state' => 'rest',
            'source' => 'manual',
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ]);
    }

    public function cleanUp(): void
    {
        foreach ($this->userIds as $userId) {
            $this->connection->delete('identity_users', ['id' => $userId]);
        }
        if ('' !== $this->workplaceId) {
            foreach ($this->poolIds as $poolId) {
                $this->connection->delete('workforce_swap_pools', ['id' => $poolId]);
            }
            $this->connection->delete('workforce_workplaces', ['id' => $this->workplaceId]);
        }
    }

    private function anyCategory(): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM workforce_staff_categories LIMIT 1');

        return \is_string($id) ? $id : '';
    }
}
