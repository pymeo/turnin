<?php

declare(strict_types=1);

namespace App\Tests\Functional\Swap;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The first exchange loop, over real HTTP.
 *
 * Pedro and María are nurses in intensive care. Antonio is a porter in A&E at
 * the same hospital — same building, different swap pool — and the most
 * important assertions in this file are the ones about what he cannot see.
 */
final class ChangesFlowTest extends WebTestCase
{
    private const SHIFT_DATE = '2027-03-18';

    private ?Connection $connection = null;

    /** @var list<string> */
    private array $userIds = [];

    private string $workplaceId = '';

    private string $uciPool = '';

    private string $portersPool = '';

    /** @var array<string, array{id: string, assignment: string, email: string}> */
    private array $workers = [];

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->userIds as $userId) {
                $this->connection->delete('identity_users', ['id' => $userId]);
            }
            if ('' !== $this->workplaceId) {
                $this->connection->delete('workforce_swap_pools', ['workplace_id' => $this->workplaceId]);
                $this->connection->delete('workforce_workplaces', ['id' => $this->workplaceId]);
            }
        }

        parent::tearDown();
    }

    public function test_the_whole_loop_from_publishing_a_shift_to_seeing_who_can_cover_it(): void
    {
        $client = $this->world();

        // ── Pedro publishes his night shift ───────────────────────────────
        $this->signIn($client, 'pedro');
        $token = $this->tokenFrom($client);
        $requestId = $this->publishedRequestId($this->json($client, 'POST', '/app/changes/publicar', [
            'assignmentId' => $this->workers['pedro']['assignment'],
            'date' => self::SHIFT_DATE,
        ], $token));

        // His own screen shows it as looking for cover, with nobody yet.
        $mine = $client->request('GET', '/app/changes/mine');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Buscando compañero', $mine->html());

        // ── María sees it and offers ──────────────────────────────────────
        $this->signIn($client, 'maria');
        $page = $client->request('GET', '/app/changes/available');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pedro quiere librar este turno', $page->html());
        self::assertStringContainsString('UCI', $page->html());
        // A card names a colleague and stops there.
        self::assertStringNotContainsString($this->workers['pedro']['email'], $page->html());

        $offer = $this->json($client, 'POST', '/app/changes/'.$requestId.'/puedo', [], $this->tokenFrom($client));
        self::assertSame(self::SHIFT_DATE, $offer['date']);

        // Tapping twice is one statement, not two.
        $this->json($client, 'POST', '/app/changes/'.$requestId.'/puedo', [], $this->tokenFrom($client));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->workers['maria']['id']]));

        // ── Pedro sees her ────────────────────────────────────────────────
        $this->signIn($client, 'pedro');
        $withCandidate = $client->request('GET', '/app/changes/mine');
        self::assertStringContainsString('1 persona', $withCandidate->html());

        $day = $this->json($client, 'GET', '/app/changes/dia?assignment='.$this->workers['pedro']['assignment'].'&date='.self::SHIFT_DATE, [], $this->tokenFrom($client));
        self::assertSame(1, $day['candidateCount']);
        self::assertIsArray($day['candidates']);
        self::assertIsArray($day['candidates'][0]);
        self::assertSame('María', $day['candidates'][0]['name']);

        // ── And the roster has not moved ──────────────────────────────────
        self::assertSame(
            'working',
            $this->scalar(
                'SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date',
                ['assignment' => $this->workers['pedro']['assignment'], 'date' => self::SHIFT_DATE],
            ),
            'Publishing a shift discovers a possibility; it does not hand the shift over.',
        );
        self::assertSame(
            0,
            $this->countRows(
                'SELECT COUNT(*) FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date',
                ['assignment' => $this->workers['maria']['assignment'], 'date' => self::SHIFT_DATE],
            ),
            'Offering to cover a shift does not put it on the volunteer\'s calendar either.',
        );
    }

    /** Same hospital, different pool. Antonio must not see any of it. */
    public function test_a_worker_from_another_pool_never_sees_the_request(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $requestId = $this->publishedRequestId($this->json($client, 'POST', '/app/changes/publicar', [
            'assignmentId' => $this->workers['pedro']['assignment'],
            'date' => self::SHIFT_DATE,
        ], $this->tokenFrom($client)));

        $this->signIn($client, 'antonio');
        $page = $client->request('GET', '/app/changes');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Pedro quiere librar este turno', $page->html());
        self::assertStringContainsString('no hay turnos compatibles', $page->html());

        // And he cannot reach it by knowing its id either.
        $client->request('POST', '/app/changes/'.$requestId.'/puedo', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: '{}');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->workers['antonio']['id']]));
    }

    public function test_a_worker_cannot_publish_somebody_elses_shift(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');

        $client->request('POST', '/app/changes/publicar', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: json_encode([
            'assignmentId' => $this->workers['pedro']['assignment'],
            'date' => self::SHIFT_DATE,
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM swap_requests', []));
    }

    public function test_a_worker_cannot_withdraw_somebody_elses_request(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $requestId = $this->publishedRequestId($this->json($client, 'POST', '/app/changes/publicar', [
            'assignmentId' => $this->workers['pedro']['assignment'],
            'date' => self::SHIFT_DATE,
        ], $this->tokenFrom($client)));

        $this->signIn($client, 'maria');
        $client->request('POST', '/app/changes/'.$requestId.'/retirar', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: '{}');

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM swap_requests WHERE status = 'open'", []));
    }

    public function test_a_worker_cannot_withdraw_somebody_elses_availability(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');
        $this->json($client, 'POST', '/app/changes/disponible', [
            'assignmentId' => $this->workers['maria']['assignment'],
            'date' => '2027-03-21',
        ], $this->tokenFrom($client));
        $availabilityId = $this->scalar('SELECT id FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->workers['maria']['id']]);

        $this->signIn($client, 'antonio');
        $client->request('POST', '/app/changes/disponibilidad/retirar', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: json_encode(['availabilityId' => $availabilityId], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE active = TRUE', []));
    }

    /** A rest day is not a shift, so there is nothing to hand over. */
    public function test_a_day_that_is_not_worked_cannot_be_published(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');

        $client->request('POST', '/app/changes/publicar', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: json_encode([
            'assignmentId' => $this->workers['maria']['assignment'],
            'date' => '2027-03-21',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $error = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($error);
        self::assertSame('Solo puedes publicar un día en el que trabajas.', $error['error'] ?? null);
    }

    /** Offering never writes a calendar: the day stays unknown. */
    public function test_declaring_availability_does_not_touch_the_roster(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');

        $this->json($client, 'POST', '/app/changes/disponible', [
            'assignmentId' => $this->workers['maria']['assignment'],
            'date' => '2027-03-25',
        ], $this->tokenFrom($client));

        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE work_date = :date', ['date' => '2027-03-25']));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM scheduling_roster_days WHERE work_date = :date', ['date' => '2027-03-25']));
    }

    public function test_a_pool_id_from_the_browser_never_grants_membership(): void
    {
        $client = $this->world();
        $this->signIn($client, 'maria');

        $client->request('POST', '/app/changes/disponible', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->tokenFrom($client),
        ], content: json_encode([
            'dates' => ['2027-03-25'],
            'shiftKinds' => ['morning'],
            'swapPoolIds' => [$this->portersPool],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->workers['maria']['id']]));
    }

    public function test_mutations_require_a_csrf_token(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');

        $client->request('POST', '/app/changes/publicar', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertSame(419, $client->getResponse()->getStatusCode());
    }

    public function test_the_lobby_leads_to_changes(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/changes"]');
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function world(): KernelBrowser
    {
        $client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $this->workplaceId = $this->seedWorkplace();
        $this->uciPool = $this->seedPool('uci');
        $this->portersPool = $this->seedPool('porters');

        $this->workers['pedro'] = $this->seedWorker('Pedro', $this->uciPool);
        $this->workers['maria'] = $this->seedWorker('María', $this->uciPool);
        $this->workers['antonio'] = $this->seedWorker('Antonio', $this->portersPool);

        // Pedro works that night; María does not, so she can offer to cover it.
        $this->seedNightShift($this->workers['pedro']['assignment'], self::SHIFT_DATE);

        return $client;
    }

    private function signIn(KernelBrowser $client, string $who): void
    {
        $worker = $this->workers[$who];
        $client->loginUser(new SecurityUser($worker['id'], $worker['email'], null, true, false));
    }

    private function tokenFrom(KernelBrowser $client): string
    {
        $page = $client->request('GET', '/app/changes');

        return (string) $page->filter('[data-changes-csrf-value]')->attr('data-changes-csrf-value');
    }

    /** @param array<string, mixed> $result */
    private function publishedRequestId(array $result): string
    {
        self::assertIsString($result['requestId'] ?? null);

        return $result['requestId'];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client, string $method, string $url, array $payload, string $token): array
    {
        $client->request($method, $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));

        $response = $client->getResponse();
        self::assertTrue($response->isSuccessful(), \sprintf('%s %s failed: %s', $method, $url, (string) $response->getContent()));
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('result', $decoded);
        self::assertIsArray($decoded['result']);

        $result = [];
        foreach ($decoded['result'] as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function seedWorkplace(): string
    {
        self::assertNotNull($this->connection);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_workplaces', [
            'id' => $id,
            'source' => 'ministry_hospitals',
            'external_id' => 'changes-'.$id,
            'name' => 'Virgen de las Nieves',
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

        return $id;
    }

    private function seedPool(string $discriminator): string
    {
        self::assertNotNull($this->connection);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('workforce_swap_pools', [
            'id' => $id,
            'workplace_id' => $this->workplaceId,
            'staff_category_id' => $this->anyCategory(),
            'specialty_id' => null,
            'organizational_unit_id' => null,
            'functional_area' => 'uci' === $discriminator ? 'UCI' : 'Urgencias',
            'employer_id' => null,
            'fingerprint' => $this->workplaceId.'|'.$discriminator,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);

        return $id;
    }

    /** @return array{id: string, assignment: string, email: string} */
    private function seedWorker(string $givenName, string $poolId): array
    {
        self::assertNotNull($this->connection);
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
            'workplace_id' => $this->workplaceId,
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

    private function seedNightShift(string $assignmentId, string $date): void
    {
        self::assertNotNull($this->connection);
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
            'label_snapshot' => 'Noche',
            'abbreviation_snapshot' => 'N',
            'starts_at' => '22:00',
            'ends_at' => '08:00',
            'kind' => 'night',
            'position' => 0,
            'color_key_snapshot' => 'blue',
        ]);
    }

    private function anyCategory(): string
    {
        self::assertNotNull($this->connection);
        $id = $this->connection->fetchOne('SELECT id FROM workforce_staff_categories LIMIT 1');
        self::assertIsString($id, 'The staff category catalogue is seeded by migrations.');

        return $id;
    }

    /** @param array<string, mixed> $parameters */
    private function countRows(string $sql, array $parameters): int
    {
        self::assertNotNull($this->connection);
        $count = $this->connection->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** @param array<string, mixed> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        self::assertNotNull($this->connection);
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($value);

        return $value;
    }
}
