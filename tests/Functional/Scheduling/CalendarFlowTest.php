<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduling;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The calendar over real HTTP: the screen renders, a draft is written, a second
 * draft does not quietly overwrite it, and dictation never writes at all.
 */
final class CalendarFlowTest extends WebTestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->userIds as $userId) {
                // Assignments, rosters, presets and patterns all cascade from here.
                $this->connection->delete('identity_users', ['id' => $userId]);
            }
        }

        parent::tearDown();
    }

    public function test_the_calendar_renders_a_six_week_grid_and_seeds_the_three_default_shifts(): void
    {
        $client = $this->workerWithAssignment();

        $page = $client->request('GET', '/app/calendar?month=2026-09');

        self::assertResponseIsSuccessful();
        self::assertCount(42, $page->filter('[data-day]'));
        self::assertSelectorTextContains('[data-calendar-target="title"]', 'Septiembre 2026');
        foreach (['Mañana', 'Tarde', 'Noche', 'Libre'] as $label) {
            self::assertStringContainsString($label, $page->filter('[data-calendar-paint-target="panel"]')->html());
        }
    }

    public function test_an_empty_month_offers_help_instead_of_a_cold_grid(): void
    {
        $client = $this->workerWithAssignment();
        $client->request('GET', '/app/calendar?month=2026-09');

        self::assertSelectorTextContains('[data-calendar-target="emptyState"]', 'Añade tu cuadrante');
    }

    public function test_a_day_with_no_information_is_not_shown_as_a_day_off(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar?month=2026-09');

        $cell = $page->filter('[data-day="2026-09-25"]');

        self::assertSame('unknown', $cell->attr('data-state'));
        self::assertStringContainsString('sin información', (string) $cell->attr('aria-label'));
    }

    public function test_painting_days_persists_them_and_the_month_shows_them_after_a_reload(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar?month=2026-09');
        $presetId = $this->firstPresetId($client);

        $result = $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'entries' => [
                ['date' => '2026-09-01', 'intent' => 'work', 'presetIds' => [$presetId]],
                ['date' => '2026-09-02', 'intent' => 'work', 'presetIds' => [$presetId]],
                ['date' => '2026-09-03', 'intent' => 'rest', 'presetIds' => []],
            ],
        ], $this->csrfTokenFrom($page));

        self::assertSame(3, $result['writtenDays']);

        $reloaded = $client->request('GET', '/app/calendar?month=2026-09');
        self::assertSame('working', $reloaded->filter('[data-day="2026-09-01"]')->attr('data-state'));
        self::assertSame('rest', $reloaded->filter('[data-day="2026-09-03"]')->attr('data-state'));
        self::assertStringContainsString('libre', (string) $reloaded->filter('[data-day="2026-09-03"]')->attr('aria-label'));
    }

    public function test_a_second_draft_does_not_overwrite_an_existing_day_unless_asked(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar?month=2026-09');
        $token = $this->csrfTokenFrom($page);
        [$morning, $evening] = $this->presetIds($client);

        $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'entries' => [['date' => '2026-09-05', 'intent' => 'work', 'presetIds' => [$morning]]],
        ], $token);

        $preview = $this->json($client, 'POST', '/app/calendar/preview', [
            'source' => 'manual',
            'entries' => [['date' => '2026-09-05', 'intent' => 'work', 'presetIds' => [$evening]]],
        ], $token);

        self::assertSame(1, $preview['conflictCount']);
        self::assertSame(0, $preview['applyCount']);
        self::assertSame('skip_existing', $preview['policy']);

        $skipped = $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'entries' => [['date' => '2026-09-05', 'intent' => 'work', 'presetIds' => [$evening]]],
        ], $token);
        self::assertSame(0, $skipped['writtenDays']);
        self::assertSame(1, $skipped['skippedConflicts']);

        $replaced = $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'policy' => 'replace_existing',
            'entries' => [['date' => '2026-09-05', 'intent' => 'work', 'presetIds' => [$evening]]],
        ], $token);
        self::assertSame(1, $replaced['writtenDays']);
    }

    public function test_clearing_a_day_is_different_from_marking_it_free(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar?month=2026-09');
        $token = $this->csrfTokenFrom($page);

        $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'entries' => [['date' => '2026-09-08', 'intent' => 'rest', 'presetIds' => []]],
        ], $token);
        self::assertSame('rest', $client->request('GET', '/app/calendar?month=2026-09')->filter('[data-day="2026-09-08"]')->attr('data-state'));

        $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'policy' => 'replace_existing',
            'entries' => [['date' => '2026-09-08', 'intent' => 'clear', 'presetIds' => []]],
        ], $token);
        self::assertSame('unknown', $client->request('GET', '/app/calendar?month=2026-09')->filter('[data-day="2026-09-08"]')->attr('data-state'));
    }

    public function test_dictated_text_is_interpreted_but_never_written(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar?month=2026-09');
        $token = $this->csrfTokenFrom($page);

        $result = $this->json($client, 'POST', '/app/calendar/interpret', [
            'text' => '1 y 2 mañana, 3 tarde, 4 noche, 5 libre',
            'month' => '2026-09',
            'source' => 'voice',
        ], $token);

        $preview = $this->nested($result, 'preview');
        self::assertSame(5, $preview['totalDays']);
        self::assertSame(4, $preview['shiftCount']);
        self::assertSame(1, $preview['restCount']);
        self::assertSame([], $preview['unrecognized']);

        // The calendar is untouched until the worker confirms.
        $untouched = $client->request('GET', '/app/calendar?month=2026-09');
        self::assertSame('unknown', $untouched->filter('[data-day="2026-09-01"]')->attr('data-state'));
    }

    public function test_a_rotation_can_be_created_previewed_and_applied_over_three_months(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar?month=2026-09');
        $token = $this->csrfTokenFrom($page);
        [$morning, $evening, $night] = $this->presetIds($client);

        $created = $this->json($client, 'POST', '/app/calendar/patrones', [
            'slots' => [$morning, $morning, $evening, $evening, $night, $night, null, null, null],
        ], $token);
        $patternId = $created['patternId'];
        self::assertIsString($patternId);

        $preview = $this->json($client, 'POST', "/app/calendar/patrones/{$patternId}/previsualizar", ['from' => '2026-09-14', 'to' => '2026-12-31'], $token);
        self::assertSame(109, $preview['totalDays']);
        self::assertSame(73, $preview['shiftCount']);
        self::assertSame(36, $preview['restCount']);

        $applied = $this->json($client, 'POST', "/app/calendar/patrones/{$patternId}/aplicar", ['from' => '2026-09-14', 'to' => '2026-12-31'], $token);
        self::assertSame(109, $applied['writtenDays']);

        // The rotation keeps its phase across the month boundary: 1 October is
        // the ninth slot (a rest day) and 2 October starts the cycle again.
        $october = $client->request('GET', '/app/calendar?month=2026-10');
        self::assertSame('rest', $october->filter('[data-day="2026-10-01"]')->attr('data-state'));
        self::assertSame('working', $october->filter('[data-day="2026-10-02"]')->attr('data-state'));
        self::assertStringContainsString('turno de mañana', (string) $october->filter('[data-day="2026-10-02"]')->attr('aria-label'));
    }

    /**
     * A roster says where a person is every day. The service worker already
     * refuses to cache navigations; this checks nothing downstream is invited to
     * either. See docs/SECURITY.md § El calendario es dato privado.
     */
    public function test_the_calendar_is_never_offered_to_a_shared_cache(): void
    {
        $client = $this->workerWithAssignment();

        foreach (['/app/calendar', '/app/calendar/grid?month=2026-09'] as $url) {
            $client->request('GET', $url);
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');

            self::assertStringContainsString('private', $cacheControl, $url.' must not be publicly cacheable.');
            self::assertStringContainsString('must-revalidate', $cacheControl, $url.' must be revalidated.');
        }
    }

    public function test_a_request_without_a_csrf_token_is_refused(): void
    {
        $client = $this->workerWithAssignment();
        $client->request('GET', '/app/calendar');

        $client->request('POST', '/app/calendar/apply', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['entries' => []], \JSON_THROW_ON_ERROR));

        self::assertSame(419, $client->getResponse()->getStatusCode());
    }

    public function test_an_account_without_a_worker_assignment_is_sent_to_onboarding(): void
    {
        $client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        $userId = $this->seedUser();
        $client->loginUser(new SecurityUser($userId, $userId.'@example.test', null, true, false));

        $client->request('GET', '/app/calendar');

        self::assertResponseRedirects('/onboarding');
    }

    public function test_the_lobby_points_at_the_calendar(): void
    {
        $client = $this->workerWithAssignment();
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/calendar"]');
    }

    public function test_shift_presets_can_be_managed_and_retired_without_losing_history(): void
    {
        $client = $this->workerWithAssignment();
        $page = $client->request('GET', '/app/calendar');
        $token = $this->csrfTokenFrom($page);
        $presetId = $this->firstPresetId($client);

        $this->json($client, 'POST', '/app/calendar/apply', [
            'source' => 'manual',
            'entries' => [['date' => '2026-09-20', 'intent' => 'work', 'presetIds' => [$presetId]]],
        ], $token);

        $this->json($client, 'POST', '/app/calendar/turnos/guardar', [
            'presetId' => $presetId,
            'name' => 'Mañana',
            'abbreviation' => 'M',
            'start' => '07:30',
            'end' => '14:30',
            'kind' => 'morning',
            'aliases' => 'manana, turno de manana',
        ], $token);

        // The stored shift keeps the hours it was created with.
        $stored = $client->request('GET', '/app/calendar?month=2026-09')->filter('[data-day="2026-09-20"]')->attr('aria-label');
        self::assertStringContainsString('de 08:00 a 15:00', (string) $stored);

        $this->json($client, 'POST', "/app/calendar/turnos/{$presetId}/retirar", [], $token);
        $management = $client->request('GET', '/app/calendar/turnos');
        self::assertResponseIsSuccessful();
        self::assertSame('false', $management->filter(\sprintf('[data-preset-id="%s"]', $presetId))->attr('data-active'));
    }

    /**
     * Posts JSON the way the Stimulus controllers do and hands back the
     * endpoint's `result` payload.
     *
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

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function nested(array $result, string $key): array
    {
        self::assertArrayHasKey($key, $result);
        self::assertIsArray($result[$key]);

        $nested = [];
        foreach ($result[$key] as $index => $value) {
            $nested[(string) $index] = $value;
        }

        return $nested;
    }

    private function csrfTokenFrom(\Symfony\Component\DomCrawler\Crawler $page): string
    {
        return (string) $page->filter('[data-calendar-csrf-value]')->attr('data-calendar-csrf-value');
    }

    private function firstPresetId(KernelBrowser $client): string
    {
        return $this->presetIds($client)[0];
    }

    /** @return list<string> */
    private function presetIds(KernelBrowser $client): array
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        /** @var list<string> $ids */
        $ids = $connection->fetchFirstColumn('SELECT id FROM scheduling_shift_presets WHERE worker_assignment_id = :assignment ORDER BY position', ['assignment' => $this->assignmentId]);
        self::assertNotEmpty($ids, 'The first visit should have seeded the default shifts.');

        return $ids;
    }

    private string $assignmentId = '';

    private function workerWithAssignment(): KernelBrowser
    {
        $client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
        $userId = $this->seedUser();
        $this->assignmentId = $this->seedAssignment($userId);
        $client->loginUser(new SecurityUser($userId, $userId.'@example.test', null, true, false));

        return $client;
    }

    private function seedUser(): string
    {
        self::assertNotNull($this->connection);
        $userId = Uuid::v7()->toRfc4122();
        $this->userIds[] = $userId;
        $this->connection->insert('identity_users', [
            'id' => $userId,
            'email' => $userId.'@example.test',
            'password_hash' => null,
            'has_worker_profile' => true,
            'has_supervisor_profile' => false,
            'created_at' => '2026-09-10T00:00:00+00:00',
        ], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);

        return $userId;
    }

    private function seedAssignment(string $userId): string
    {
        self::assertNotNull($this->connection);
        $workplaceId = Uuid::v7()->toRfc4122();
        $assignmentId = Uuid::v7()->toRfc4122();

        $this->connection->insert('workforce_workplaces', [
            'id' => $workplaceId,
            'source' => 'ministry_hospitals',
            'external_id' => 'calendar-'.$assignmentId,
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

        $category = $this->connection->fetchOne('SELECT id FROM workforce_staff_categories LIMIT 1');
        self::assertIsString($category, 'The staff category catalogue is seeded by migrations.');

        $this->connection->insert('workforce_worker_assignments', [
            'id' => $assignmentId,
            'worker_id' => $userId,
            'workplace_id' => $workplaceId,
            'staff_category_id' => $category,
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
}
