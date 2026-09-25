<?php

declare(strict_types=1);

namespace App\Tests\Functional\Swap;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use App\Swap\Application\Command\ReviewSwapApproval;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
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
        $returnDate = '2027-03-20';
        $client = $this->world();
        // Pedro already has a non-overlapping morning on the date of María's
        // night. That is informative context, not a reason to reject the swap.
        $this->seedShift($this->workers['pedro']['assignment'], $returnDate, '08:00', '15:00', 'Mañana', 'M', 'morning', 'amber');

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
        self::assertStringContainsString('Todavía nadie se ha ofrecido', $mine->html());

        // ── María sees it and offers ──────────────────────────────────────
        $this->signIn($client, 'maria');
        $page = $client->request('GET', '/app/changes/available');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pedro quiere librar', $page->html());
        self::assertStringContainsString('10 H', $page->html());
        self::assertStringContainsString('Puedes hacer este turno', $page->html());
        self::assertStringContainsString('Se lo hago', $page->html());
        self::assertStringContainsString('UCI', $page->html());
        // A card names a colleague and stops there.
        self::assertStringNotContainsString($this->workers['pedro']['email'], $page->html());

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM swap_availabilities WHERE worker_id = :worker', ['worker' => $this->workers['maria']['id']]), 'Availability is not required.');

        // ── María sends one real return option ───────────────────────────
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM swap_proposals WHERE proposer_id = :worker', ['worker' => $this->workers['maria']['id']]));
        $client->request('POST', '/app/changes/'.$requestId.'/propuestas', [
            '_token' => $this->tokenFrom($client),
            'offeredShifts' => [$this->workers['maria']['assignment'].'|'.$returnDate],
        ]);
        self::assertResponseRedirects('/app/changes/proposals?enviada=1');
        $proposalId = $this->scalar('SELECT id FROM swap_proposals WHERE proposer_id = :worker', ['worker' => $this->workers['maria']['id']]);
        $optionId = $this->scalar('SELECT id FROM swap_proposal_options WHERE proposal_id = :proposal', ['proposal' => $proposalId]);
        self::assertSame('pending', $this->scalar('SELECT status FROM swap_proposals WHERE id = :id', ['id' => $proposalId]));
        self::assertSame('working', $this->scalar('SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $this->workers['pedro']['assignment'], 'date' => self::SHIFT_DATE]));

        // ── Pedro accepts; only now are both rosters changed ──────────────
        $this->signIn($client, 'pedro');
        $decision = $client->request('GET', '/app/changes/proposals/'.$proposalId);
        self::assertStringContainsString('María te hace el turno', $decision->html());
        self::assertStringContainsString('Elige qué turno puedes hacerle tú', $decision->html());
        $client->request('POST', '/app/changes/proposals/'.$proposalId.'/aceptar', ['_token' => $this->tokenFrom($client), 'optionId' => $optionId]);
        self::assertResponseRedirects('/app/changes/agreements/'.$proposalId);
        self::assertSame(
            'rest',
            $this->scalar(
                'SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date',
                ['assignment' => $this->workers['pedro']['assignment'], 'date' => self::SHIFT_DATE],
            ),
            'The original worker is released from their shift.',
        );
        self::assertSame(
            1,
            $this->countRows(
                'SELECT COUNT(*) FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date',
                ['assignment' => $this->workers['maria']['assignment'], 'date' => self::SHIFT_DATE],
            ),
            'The proposer receives the published shift.',
        );
        self::assertSame('working', $this->scalar('SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $this->workers['pedro']['assignment'], 'date' => $returnDate]));
        self::assertSame(2, $this->countRows(
            'SELECT COUNT(*) FROM scheduling_roster_segments s JOIN scheduling_roster_days d ON d.id = s.roster_day_id WHERE d.worker_assignment_id = :assignment AND d.work_date = :date',
            ['assignment' => $this->workers['pedro']['assignment'], 'date' => $returnDate],
        ), 'The existing non-overlapping shift is preserved beside the received shift.');
        self::assertSame('rest', $this->scalar('SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $this->workers['maria']['assignment'], 'date' => $returnDate]));
        self::assertSame('swap', $this->scalar('SELECT source FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $this->workers['pedro']['assignment'], 'date' => $returnDate]));
        self::assertSame('executed', $this->scalar('SELECT status FROM swap_proposals WHERE id = :id', ['id' => $proposalId]));
        self::assertSame('covered', $this->scalar('SELECT status FROM swap_requests WHERE id = :id', ['id' => $requestId]));

        // The accepted proposal becomes one stable agreement resource. The
        // proposal notification is only for Pedro; the agreement reaches both.
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM swap_agreement_snapshots WHERE proposal_id = :proposal', ['proposal' => $proposalId]));
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM notification_user_notifications WHERE recipient_id = :recipient', ['recipient' => $this->workers['pedro']['id']]));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM notification_user_notifications WHERE recipient_id = :recipient', ['recipient' => $this->workers['maria']['id']]));

        $agreement = $client->request('GET', '/app/changes/agreements/'.$proposalId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MARÍA HARÁ POR PEDRO', mb_strtoupper($agreement->html()));
        self::assertStringContainsString('PEDRO HARÁ POR MARÍA', mb_strtoupper($agreement->html()));
        self::assertStringContainsString('Cambio confirmado', $agreement->html());
        self::assertStringContainsString('Compartir con responsable', $agreement->html());
        $publicUrl = (string) $agreement->filter('[data-agreement-share-url-value]')->attr('data-agreement-share-url-value');
        self::assertMatchesRegularExpression('#/cambio/[A-Za-z0-9_-]{43}$#', $publicUrl);
        self::assertStringNotContainsString($proposalId, $publicUrl);

        $notificationToken = (string) $agreement->filter('[data-notifications-csrf-value]')->attr('data-notifications-csrf-value');
        $client->request('GET', '/app/notifications');
        self::assertResponseIsSuccessful();
        $notificationPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(2, $notificationPayload['unreadCount'] ?? null);
        $firstNotificationId = $notificationPayload['notifications'][0]['id'] ?? null;
        self::assertIsString($firstNotificationId);
        $client->request('POST', '/app/notifications/'.$firstNotificationId.'/read', server: ['HTTP_X_CSRF_TOKEN' => $notificationToken]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['unreadCount'] ?? null);
        $client->request('POST', '/app/notifications/read-all', server: ['HTTP_X_CSRF_TOKEN' => $notificationToken]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM notification_user_notifications WHERE recipient_id = :recipient AND read_at IS NULL', ['recipient' => $this->workers['pedro']['id']]));

        $client->getCookieJar()->clear();
        $publicPath = (string) parse_url($publicUrl, \PHP_URL_PATH);
        $publicAgreement = $client->request('GET', $publicPath);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Consulta de solo lectura', $publicAgreement->html());
        self::assertStringContainsString('noindex,nofollow', $publicAgreement->html());
        self::assertStringNotContainsString($this->workers['pedro']['email'], $publicAgreement->html());
        self::assertStringNotContainsString($this->workers['maria']['email'], $publicAgreement->html());
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $client->request('POST', $publicPath);
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/cambio/'.str_repeat('x', 43));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_profile_edit_after_publishing_does_not_strand_the_agreement_on_the_old_team_assignment(): void
    {
        $client = $this->world();
        $this->signIn($client, 'pedro');
        $requestId = $this->publishedRequestId($this->json($client, 'POST', '/app/changes/publicar', [
            'assignmentId' => $this->workers['pedro']['assignment'],
            'date' => self::SHIFT_DATE,
        ], $this->tokenFrom($client)));

        $this->signIn($client, 'maria');
        $client->request('POST', '/app/changes/'.$requestId.'/propuestas', [
            '_token' => $this->tokenFrom($client),
            'offeredShifts' => [$this->workers['maria']['assignment'].'|2027-03-20'],
        ]);
        self::assertResponseRedirects('/app/changes/proposals?enviada=1');
        $proposalId = $this->scalar('SELECT id FROM swap_proposals WHERE request_id = :request', ['request' => $requestId]);
        $optionId = $this->scalar('SELECT id FROM swap_proposal_options WHERE proposal_id = :proposal', ['proposal' => $proposalId]);

        // Regression for the real failure: the request still names the old
        // calendar, while the worker now reaches the same pool through a new
        // active assignment.
        $currentPedroCalendar = $this->replaceActiveAssignment($this->workers['pedro']['id'], $this->workers['pedro']['assignment'], $this->uciPool);
        $this->seedNightShift($currentPedroCalendar, self::SHIFT_DATE);

        $this->signIn($client, 'pedro');
        $client->request('POST', '/app/changes/proposals/'.$proposalId.'/aceptar', ['_token' => $this->tokenFrom($client), 'optionId' => $optionId]);

        self::assertResponseRedirects('/app/changes/agreements/'.$proposalId);
        self::assertSame('executed', $this->scalar('SELECT status FROM swap_proposals WHERE id = :id', ['id' => $proposalId]));
        self::assertSame('working', $this->scalar('SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $currentPedroCalendar, 'date' => '2027-03-20']));
        self::assertSame('swap', $this->scalar('SELECT source FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $currentPedroCalendar, 'date' => '2027-03-20']));
        self::assertSame('rest', $this->scalar('SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $currentPedroCalendar, 'date' => self::SHIFT_DATE]), 'A copied source shift is also released from the current visible calendar.');
    }

    public function test_an_agreement_that_requires_approval_stays_pending_until_the_supervisor_approves_it(): void
    {
        $client = $this->world();
        self::assertNotNull($this->connection);
        $this->connection->insert('workforce_shift_exchange_policies', [
            'swap_pool_id' => $this->uciPool,
            'requires_approval' => true,
            'allows_coverage' => false,
            'updated_at' => '2026-09-24T18:00:00+00:00',
        ], ['requires_approval' => 'boolean', 'allows_coverage' => 'boolean']);
        $this->connection->insert('workforce_swap_supervisors', [
            'swap_pool_id' => $this->uciPool,
            'supervisor_user_id' => $this->workers['antonio']['id'],
            'active' => true,
            'created_at' => '2026-09-24T18:00:00+00:00',
        ], ['active' => 'boolean']);

        $this->signIn($client, 'pedro');
        $requestId = $this->publishedRequestId($this->json($client, 'POST', '/app/changes/publicar', [
            'assignmentId' => $this->workers['pedro']['assignment'],
            'date' => self::SHIFT_DATE,
        ], $this->tokenFrom($client)));
        $this->signIn($client, 'maria');
        $client->request('POST', '/app/changes/'.$requestId.'/propuestas', [
            '_token' => $this->tokenFrom($client),
            'offeredShifts' => [$this->workers['maria']['assignment'].'|2027-03-20'],
        ]);
        $proposalId = $this->scalar('SELECT id FROM swap_proposals WHERE request_id = :request', ['request' => $requestId]);
        $optionId = $this->scalar('SELECT id FROM swap_proposal_options WHERE proposal_id = :proposal', ['proposal' => $proposalId]);

        $this->signIn($client, 'pedro');
        $client->request('POST', '/app/changes/proposals/'.$proposalId.'/aceptar', ['_token' => $this->tokenFrom($client), 'optionId' => $optionId]);
        self::assertResponseRedirects('/app/changes/agreements/'.$proposalId);
        self::assertSame('pending_approval', $this->scalar('SELECT status FROM swap_proposals WHERE id = :id', ['id' => $proposalId]));
        self::assertSame('working', $this->scalar('SELECT state FROM scheduling_roster_days WHERE worker_assignment_id = :assignment AND work_date = :date', ['assignment' => $this->workers['pedro']['assignment'], 'date' => self::SHIFT_DATE]));
        $pending = $client->request('GET', '/app/changes/agreements/'.$proposalId);
        self::assertStringContainsString('Acordado entre compañeros', $pending->html());
        self::assertStringContainsString('Falta la aprobación/registro del responsable', $pending->html());

        $bus = static::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new ReviewSwapApproval($this->workers['antonio']['id'], $proposalId, 'approve'));

        self::assertSame('executed', $this->scalar('SELECT status FROM swap_proposals WHERE id = :id', ['id' => $proposalId]));
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM notification_user_notifications WHERE recipient_id = :recipient AND type IN ('swap_agreement', 'swap_approved')", ['recipient' => $this->workers['maria']['id']]));
        $approved = $client->request('GET', '/app/changes/agreements/'.$proposalId);
        self::assertStringContainsString('Cambio confirmado', $approved->html());
        self::assertStringNotContainsString('Falta la aprobación', $approved->html());
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
        $page = $client->request('GET', '/app/changes/available');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Pedro quiere librarse', $page->html());
        self::assertStringContainsString('no hay turnos de compañeros', strtolower($page->html()));

        // And he cannot reach it by knowing its id either.
        $client->request('GET', '/app/changes/'.$requestId.'/intercambio');
        self::assertSame(404, $client->getResponse()->getStatusCode());
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

    public function test_an_unnamed_legacy_pool_is_not_offered_as_a_valid_group(): void
    {
        $client = $this->world();
        $invalidPool = Uuid::v7()->toRfc4122();
        self::assertNotNull($this->connection);
        $this->connection->insert('workforce_swap_pools', [
            'id' => $invalidPool,
            'workplace_id' => $this->workplaceId,
            'staff_category_id' => $this->anyCategory(),
            'specialty_id' => null,
            'organizational_unit_id' => null,
            'functional_area' => null,
            'employer_id' => null,
            'fingerprint' => $this->workplaceId.'|unnamed-legacy',
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['active' => 'boolean']);
        $this->connection->insert('workforce_swap_pool_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'swap_pool_id' => $invalidPool,
            'worker_id' => $this->workers['maria']['id'],
            'assignment_id' => $this->workers['maria']['assignment'],
            'source' => 'self_declared',
            'is_primary' => false,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['is_primary' => 'boolean', 'active' => 'boolean']);

        $this->signIn($client, 'maria');
        $page = $client->request('GET', '/app/changes');
        self::assertResponseIsSuccessful();
        $groups = json_decode((string) $page->filter('[data-changes-groups-value]')->attr('data-changes-groups-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($groups);
        self::assertCount(1, $groups);
        self::assertStringNotContainsString('nombre no disponible', $page->html());
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
        $this->seedNightShift($this->workers['maria']['assignment'], '2027-03-20');

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
        $this->seedShift($assignmentId, $date, '22:00', '08:00', 'Noche', 'N', 'night', 'blue');
    }

    private function replaceActiveAssignment(string $workerId, string $previousAssignmentId, string $poolId): string
    {
        self::assertNotNull($this->connection);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement('UPDATE workforce_worker_assignments SET active = FALSE, primary_assignment = FALSE WHERE id = :id', ['id' => $previousAssignmentId]);
        $this->connection->executeStatement('UPDATE workforce_swap_pool_memberships SET active = FALSE WHERE assignment_id = :id', ['id' => $previousAssignmentId]);
        $this->connection->executeStatement(
            'INSERT INTO workforce_worker_assignments (id, worker_id, workplace_id, staff_category_id, specialty_id, organizational_unit_id, functional_area, employer_id, primary_assignment, active, created_at, updated_at) SELECT :new, worker_id, workplace_id, staff_category_id, specialty_id, organizational_unit_id, functional_area, employer_id, TRUE, TRUE, created_at, updated_at FROM workforce_worker_assignments WHERE id = :old',
            ['new' => $id, 'old' => $previousAssignmentId],
        );
        $this->connection->insert('workforce_swap_pool_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'swap_pool_id' => $poolId,
            'worker_id' => $workerId,
            'assignment_id' => $id,
            'source' => 'self_declared',
            'is_primary' => true,
            'active' => true,
            'created_at' => '2026-09-10T00:00:00+00:00',
            'updated_at' => '2026-09-10T00:00:00+00:00',
        ], ['is_primary' => 'boolean', 'active' => 'boolean']);

        return $id;
    }

    private function seedShift(string $assignmentId, string $date, string $startsAt, string $endsAt, string $label, string $abbreviation, string $kind, string $color): void
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
            'label_snapshot' => $label,
            'abbreviation_snapshot' => $abbreviation,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'kind' => $kind,
            'position' => 0,
            'color_key_snapshot' => $color,
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
