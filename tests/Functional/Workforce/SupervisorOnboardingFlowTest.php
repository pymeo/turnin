<?php

declare(strict_types=1);

namespace App\Tests\Functional\Workforce;

use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use App\Tests\Support\Identity\FakeGoogleProvider;
use App\Tests\Support\Swap\SwapWorldSeed;
use App\Workforce\Infrastructure\Http\SupervisionController;
use Doctrine\DBAL\Connection;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Supervisor onboarding through the real HTTP surface: a colleague invites,
 * the supervisor accepts, the team vouches, and only then may they approve.
 *
 * UCI has Ana, David and Eva (quorum 2). Urgencias has Otto and Pía, and is
 * the pool nobody here may touch. Laura is a Google account with no job in
 * either.
 */
#[CoversClass(SupervisionController::class)]
final class SupervisorOnboardingFlowTest extends WebTestCase
{
    // Dates no other functional test uses, because some of them count rows
    // by date across the whole database.
    private const string ANA_SHIFT = '2027-05-11';
    private const string DAVID_SHIFT = '2027-05-13';

    private ?SwapWorldSeed $seed = null;

    private ?Connection $connection = null;

    private ?FakeGoogleProvider $google = null;

    private string $uci = '';

    private string $urgencias = '';

    /** @var array<string, array{id: string, assignment: string, email: string}> */
    private array $people = [];

    /** @var list<string> */
    private array $extraUsers = [];

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->extraUsers as $userId) {
                $this->connection->delete('identity_users', ['id' => $userId]);
            }
        }
        $this->seed?->cleanUp();

        parent::tearDown();
    }

    public function test_from_invitation_to_verified_supervisor_who_approves_a_change_that_was_already_waiting(): void
    {
        $client = $this->world();
        // Reached before anybody could approve it: it must wait, and it must
        // not be lost when a supervisor is verified later.
        $waiting = $this->pendingApproval($client, 'ana', 'david');
        $foreign = $this->pendingApproval($client, 'otto', 'pia', $this->urgencias);

        $this->signIn($client, 'ana');
        $home = $client->request('GET', '/app');
        self::assertCount(1, $home->filter('[data-testid="supervisor-missing-card"]'));
        self::assertStringContainsString('Todavía no tenéis un responsable verificado', $home->html());
        $token = $this->invite($client, $this->uci);

        // ── Laura opens the link before signing in ─────────────────────────
        $client->restart();
        $landing = $client->request('GET', '/invitacion/responsable/'.$token);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Te han invitado como responsable', $landing->html());
        self::assertStringContainsString('UCI', $landing->html());
        self::assertCount(1, $landing->filter('a[href="/auth/google"]'));

        // ── signed in, she accepts; a double tap changes nothing ───────────
        $this->signIn($client, 'laura');
        $invitation = $client->request('GET', '/invitacion/responsable/'.$token);
        self::assertStringContainsString('Los miembros del equipo confirmarán', $invitation->html());
        $accept = $invitation->filter('form[action$="/aceptar"]')->form();
        $client->submit($accept);
        self::assertResponseRedirects('/app');
        $client->submit($accept);
        self::assertResponseRedirects('/app');

        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura AND status = 'pending_verification'", ['laura' => $this->people['laura']['id']]));
        $assignmentId = $this->scalar('SELECT id FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura', ['laura' => $this->people['laura']['id']]);
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a AND source = 'invitation' AND verifier_worker_id = :ana", ['a' => $assignmentId, 'ana' => $this->people['ana']['id']]), 'The inviter counts once, as an explicit row.');
        self::assertTrue((bool) $this->scalar('SELECT has_supervisor_profile FROM identity_users WHERE id = :id', ['id' => $this->people['laura']['id']]));

        // ── the team is asked, the inviter only told ───────────────────────
        foreach (['david', 'eva'] as $colleague) {
            self::assertSame(1, $this->notifications($colleague, 'supervisor_verification'));
        }
        self::assertSame(0, $this->notifications('ana', 'supervisor_verification'));
        self::assertSame(1, $this->notifications('ana', 'supervisor_invitation'));
        self::assertSame(0, $this->notifications('otto', 'supervisor_verification'));
        $verificationPath = $this->scalar("SELECT target_url FROM notification_user_notifications WHERE recipient_id = :david AND type = 'supervisor_verification'", ['david' => $this->people['david']['id']]);
        self::assertMatchesRegularExpression('#^/app/equipo/responsable/verificar/[A-Za-z0-9_-]{43}$#', $verificationPath);
        self::assertStringNotContainsString($assignmentId, $verificationPath);

        // ── pending: progress on her home, no authority anywhere ───────────
        $pendingHome = $client->request('GET', '/app');
        self::assertSelectorTextContains('[data-testid="supervisor-progress"]', '1 de 2 confirmaciones');
        $share = $pendingHome->filter('[data-testid="supervisor-pending-card"] [data-controller="supervisor-share"]');
        self::assertStringEndsWith('/app/equipo/responsable/verificar/'.substr($verificationPath, (int) strrpos($verificationPath, '/') + 1), (string) $share->attr('data-supervisor-share-url-value'));
        self::assertStringContainsString('me he registrado en Turnin como responsable de UCI', (string) $share->attr('data-supervisor-share-message-value'));
        $client->request('GET', '/app/responsable');
        self::assertResponseStatusCodeSame(403);
        $this->review($client, $waiting, 'approve');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('pending_approval', $this->proposalStatus($waiting));

        // ── outsiders with the link cannot vote, nor learn who it is ───────
        $this->signIn($client, 'otto');
        $outsider = $client->request('GET', $verificationPath);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('No perteneces a este equipo', $outsider->html());
        self::assertStringNotContainsString('Laura', $outsider->html());
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertResponseStatusCodeSame(403);
        $this->signIn($client, 'laura');
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a', ['a' => $assignmentId]));

        // ── David recognises her and confirms: quorum, exactly once ────────
        $this->signIn($client, 'david');
        self::assertCount(1, $client->request('GET', '/app')->filter('[data-testid="supervisor-check-card"]'));
        $screen = $client->request('GET', $verificationPath);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Laura García', $screen->html());
        self::assertStringContainsString('lau***@', $screen->html());
        self::assertStringNotContainsString($this->people['laura']['email'], $screen->html());
        $confirm = $screen->filter('form input[name="decision"][value="confirmed"]')->closest('form');
        self::assertNotNull($confirm);
        $client->submit($confirm->form());
        self::assertResponseRedirects($verificationPath);
        $client->submit($confirm->form());

        self::assertSame('verified', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a', ['a' => $assignmentId]));
        self::assertSame(1, $this->notifications('laura', 'supervisor_verified'));
        self::assertSame(1, $this->notifications('laura', 'supervisor_pending_approvals'), 'The change agreed before she was verified is announced.');
        self::assertSame(1, $this->notifications('eva', 'supervisor_team_update'));
        self::assertCount(0, $client->request('GET', '/app')->filter('[data-testid="supervisor-check-card"]'), 'Once answered, no more nagging.');

        // ── verified: the old change is already on her dashboard ───────────
        $this->signIn($client, 'laura');
        self::assertCount(1, $client->request('GET', '/app')->filter('[data-testid="supervisor-verified-card"]'));
        $dashboard = $client->request('GET', '/app/responsable');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $dashboard->filter('[data-testid="supervisor-pending-change"]'));
        self::assertStringContainsString('Ana', $dashboard->html());
        self::assertStringNotContainsString('Otto', $dashboard->html());
        $this->review($client, $waiting, 'approve');
        self::assertResponseRedirects('/app/responsable?hecho=aprobado');
        self::assertSame('executed', $this->proposalStatus($waiting));
        self::assertSame($this->people['laura']['id'], $this->scalar('SELECT approved_by FROM swap_proposals WHERE id = :id', ['id' => $waiting]));

        // ── and nothing from the pool she does not supervise ───────────────
        $this->review($client, $foreign, 'approve');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('pending_approval', $this->proposalStatus($foreign));

        $this->signIn($client, 'ana');
        self::assertStringContainsString('Aprobado por Laura', $client->request('GET', '/app/changes/agreements/'.$waiting)->html());
    }

    public function test_a_supervisor_who_leaves_loses_access_at_once_and_the_history_stays(): void
    {
        $client = $this->world();
        $assignmentId = $this->verifiedLaura($client);
        $approved = $this->pendingApproval($client, 'ana', 'david');
        $this->signIn($client, 'laura');
        $this->review($client, $approved, 'approve');
        self::assertSame('executed', $this->proposalStatus($approved));
        $stillWaiting = $this->pendingApproval($client, 'eva', 'david', agreementDay: '2027-05-18', returnDay: '2027-05-20');

        $this->signIn($client, 'laura');
        $confirmation = $client->request('GET', '/app/equipo/responsable/'.$assignmentId.'/dejar');
        self::assertStringContainsString('¿Quieres dejar de ser responsable de UCI', $confirmation->html());
        self::assertStringContainsString('Tu historial de acciones anteriores se conservará', $confirmation->html());
        $client->submit($confirmation->filter('form[action$="/dejar"]')->form());
        self::assertResponseRedirects('/app?responsable=dejado');

        self::assertSame('left', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertNotSame('', $this->scalar('SELECT left_at FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a', ['a' => $assignmentId]));
        $client->request('GET', '/app/responsable');
        self::assertResponseStatusCodeSame(403);
        $this->review($client, $stillWaiting, 'approve');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('pending_approval', $this->proposalStatus($stillWaiting), 'Nothing is approved because nobody is left to approve it.');
        self::assertCount(0, $client->request('GET', '/app')->filter('[data-testid="supervisor-verified-card"]'));

        $this->signIn($client, 'eva');
        self::assertSame(1, $this->notifications('eva', 'supervisor_team_update', 'Vuestro equipo se ha quedado sin responsable verificado'));
        $team = $client->request('GET', '/app/equipo');
        self::assertCount(1, $team->filter('[data-testid="team-without-supervisor"]'));
        $this->signIn($client, 'ana');
        self::assertStringContainsString('Aprobado por Laura', $client->request('GET', '/app/changes/agreements/'.$approved)->html(), 'Past approvals still say who gave them.');
    }

    public function test_a_pending_supervisor_can_withdraw_and_the_verification_link_stops_working(): void
    {
        $client = $this->world();
        $this->signIn($client, 'ana');
        $token = $this->invite($client, $this->uci);
        $this->signIn($client, 'laura');
        $client->submit($client->request('GET', '/invitacion/responsable/'.$token)->filter('form[action$="/aceptar"]')->form());
        $assignmentId = $this->scalar('SELECT id FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura', ['laura' => $this->people['laura']['id']]);
        $verificationPath = $this->scalar("SELECT target_url FROM notification_user_notifications WHERE recipient_id = :david AND type = 'supervisor_verification'", ['david' => $this->people['david']['id']]);

        $client->submit($client->request('GET', '/app/equipo/responsable/'.$assignmentId.'/dejar')->filter('form[action$="/dejar"]')->form());
        self::assertSame('left', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));

        $this->signIn($client, 'david');
        self::assertStringContainsString('Esta solicitud de responsable ya no está activa', $client->request('GET', $verificationPath)->html());
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a', ['a' => $assignmentId]));
    }

    public function test_declining_creates_no_assignment_and_tells_the_inviter(): void
    {
        $client = $this->world();
        $this->signIn($client, 'ana');
        $token = $this->invite($client, $this->uci);

        $this->signIn($client, 'laura');
        $client->submit($client->request('GET', '/invitacion/responsable/'.$token)->filter('form[action$="/rechazar"]')->form());
        self::assertResponseRedirects('/app?responsable=rechazado');

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura', ['laura' => $this->people['laura']['id']]));
        self::assertSame('declined', $this->scalar('SELECT status FROM workforce_supervisor_invitations WHERE swap_pool_id = :pool', ['pool' => $this->uci]));
        self::assertSame(1, $this->notifications('ana', 'supervisor_invitation', 'Laura García ha rechazado la invitación'));
    }

    public function test_an_expired_invitation_asks_for_a_new_one_and_cannot_be_accepted(): void
    {
        $client = $this->world();
        $this->signIn($client, 'ana');
        $token = $this->invite($client, $this->uci);
        self::assertNotNull($this->connection);
        $this->connection->executeStatement("UPDATE workforce_supervisor_invitations SET created_at = now() - interval '9 days', expires_at = now() - interval '2 days' WHERE swap_pool_id = :pool", ['pool' => $this->uci]);

        $client->restart();
        self::assertStringContainsString('Esta invitación ha caducado', $client->request('GET', '/invitacion/responsable/'.$token)->html());
        $this->signIn($client, 'laura');
        $page = $client->request('GET', '/invitacion/responsable/'.$token);
        self::assertStringContainsString('ha caducado', $page->html());
        self::assertCount(0, $page->filter('form[action$="/aceptar"]'));
        $client->request('POST', '/invitacion/responsable/'.$token.'/aceptar', ['_token' => $this->csrf($client)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura', ['laura' => $this->people['laura']['id']]));
    }

    public function test_only_members_can_invite_and_a_new_link_replaces_the_previous_one(): void
    {
        $client = $this->world();
        $this->signIn($client, 'otto');
        $client->request('POST', '/app/equipo/'.$this->uci.'/invitar-responsable', ['_token' => $this->csrf($client)]);
        self::assertResponseStatusCodeSame(403);

        $this->signIn($client, 'ana');
        $first = $this->invite($client, $this->uci);
        $second = $this->invite($client, $this->uci);
        self::assertNotSame($first, $second);
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM workforce_supervisor_invitations WHERE swap_pool_id = :pool AND status = 'pending'", ['pool' => $this->uci]));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_invitations WHERE token_hash = :first', ['first' => $first]), 'Only hashes are stored.');
    }

    public function test_a_team_too_small_for_quorum_says_so_instead_of_verifying(): void
    {
        $client = $this->world();
        $this->seed ??= new SwapWorldSeed($this->db());
        $tiny = $this->seed->pool('Paritorio');
        $this->people['sole'] = $this->seed->worker('Sole', $tiny);

        $this->signIn($client, 'sole');
        $token = $this->invite($client, $tiny);
        $this->signIn($client, 'laura');
        $client->submit($client->request('GET', '/invitacion/responsable/'.$token)->filter('form[action$="/aceptar"]')->form());

        $home = $client->request('GET', '/app');
        self::assertStringContainsString('Actualmente no hay suficientes compañeros en Turnin para verificarte', $home->html());
        self::assertSame('pending_verification', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura', ['laura' => $this->people['laura']['id']]));
    }

    /**
     * The OAuth round trip itself: anonymous link → Google → back to the very
     * same link, and the same for a colleague's verification link. Google's
     * servers are the only thing faked.
     */
    public function test_google_sign_in_returns_to_the_invitation_and_to_the_verification_link(): void
    {
        $client = $this->world();
        $this->signIn($client, 'ana');
        $token = $this->invite($client, $this->uci);
        $client->restart();

        $email = 'laura.google.'.bin2hex(random_bytes(4)).'@hospital.example';
        $this->fakeGoogle($client, ['sub' => 'google-'.bin2hex(random_bytes(6)), 'email' => $email, 'email_verified' => true, 'given_name' => 'Laura', 'family_name' => 'García']);
        $client->request('GET', '/invitacion/responsable/'.$token);
        $this->googleRoundTrip($client);
        self::assertResponseRedirects('/invitacion/responsable/'.$token);
        $userId = $this->scalar('SELECT id FROM identity_users WHERE email = :email', ['email' => $email]);
        $this->extraUsers[] = $userId;
        $accept = $client->followRedirect()->filter('form[action$="/aceptar"]');
        self::assertCount(1, $accept, 'Back on the invitation, ready to accept — not on a generic home.');
        $client->submit($accept->form());
        self::assertResponseRedirects('/app');
        self::assertSame('pending_verification', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $userId]));

        // David follows the WhatsApp link while signed out.
        $verificationPath = $this->scalar("SELECT target_url FROM notification_user_notifications WHERE recipient_id = :david AND type = 'supervisor_verification'", ['david' => $this->people['david']['id']]);
        $this->db()->insert('identity_external_identities', ['id' => Uuid::v7()->toRfc4122(), 'user_id' => $this->people['david']['id'], 'provider' => 'google', 'provider_subject' => 'google-david-'.$this->people['david']['id'], 'email_at_link_time' => $this->people['david']['email'], 'created_at' => '2026-09-10T00:00:00+00:00']);
        $client->restart();
        $this->fakeGoogle($client, ['sub' => 'google-david-'.$this->people['david']['id'], 'email' => $this->people['david']['email'], 'email_verified' => true, 'given_name' => 'David', 'family_name' => 'de Prueba']);
        $client->request('GET', $verificationPath);
        self::assertResponseRedirects('http://localhost/login');
        $this->googleRoundTrip($client);
        self::assertResponseRedirects($verificationPath);
        self::assertStringContainsString('Laura García', $client->followRedirect()->html());
    }

    public function test_a_member_asks_to_be_supervisor_starts_at_zero_and_the_team_verifies_her(): void
    {
        $client = $this->world();
        $this->people['lauraWorker'] = $this->worker('Laura', $this->uci);

        $this->signIn($client, 'lauraWorker');
        $home = $client->request('GET', '/app');
        self::assertStringContainsString('¿Eres tú quien normalmente gestiona los cambios?', $home->html());
        $form = $client->request('GET', '/app/equipo/responsable/solicitar?equipo='.$this->uci);
        self::assertStringContainsString('Necesitarás 2 confirmaciones', $form->html());
        self::assertStringNotContainsString('Urgencias', $form->html(), 'Only pools she works in are offered.');
        $submit = $form->filter('form[action="/app/equipo/responsable/solicitar"]')->form();
        $client->submit($submit);
        self::assertResponseRedirects('/app?responsable=solicitado');
        $client->submit($submit);
        self::assertResponseRedirects('/app?responsable=en-curso', null, 'Asking twice points at the running request.');

        $laura = $this->people['lauraWorker']['id'];
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $laura]));
        $assignmentId = $this->scalar('SELECT id FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $laura]);
        self::assertSame('pending_verification', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertSame('self_request', $this->scalar('SELECT origin FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertSame('', $this->scalar('SELECT invitation_id FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a', ['a' => $assignmentId]), 'Nobody vouched: nothing is counted.');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_invitations WHERE swap_pool_id = :pool', ['pool' => $this->uci]));

        // The team is asked; Laura is not; outsiders are not.
        foreach (['ana', 'david', 'eva'] as $colleague) {
            self::assertSame(1, $this->notifications($colleague, 'supervisor_verification', 'Comprueba a tu responsable'));
        }
        self::assertSame(0, $this->notifications('lauraWorker', 'supervisor_verification'));
        self::assertSame(0, $this->notifications('otto', 'supervisor_verification'));
        $verificationPath = $this->scalar("SELECT target_url FROM notification_user_notifications WHERE recipient_id = :ana AND type = 'supervisor_verification'", ['ana' => $this->people['ana']['id']]);
        self::assertMatchesRegularExpression('#^/app/equipo/responsable/verificar/[A-Za-z0-9_-]{43}$#', $verificationPath);
        self::assertStringContainsString('Laura de Prueba quiere figurar como responsable de UCI', $this->scalar("SELECT body FROM notification_user_notifications WHERE recipient_id = :ana AND type = 'supervisor_verification'", ['ana' => $this->people['ana']['id']]));

        // Pending: the same home card, 0 of 2, and no authority.
        $client->request('GET', '/app');
        self::assertSelectorTextContains('[data-testid="supervisor-progress"]', '0 de 2 confirmaciones');
        $client->request('GET', '/app/responsable');
        self::assertResponseStatusCodeSame(403);

        // She cannot vote for herself, even as a member of the pool.
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_verifications WHERE supervisor_assignment_id = :a', ['a' => $assignmentId]));

        $this->signIn($client, 'ana');
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertSame('pending_verification', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        $this->signIn($client, 'david');
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertSame('verified', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $assignmentId]));
        self::assertSame(1, $this->notifications('lauraWorker', 'supervisor_verified'));

        $this->signIn($client, 'lauraWorker');
        $client->request('GET', '/app/responsable');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/app/equipo/responsable/solicitar', ['_token' => $this->csrf($client), 'swapPoolIds' => [$this->uci]]);
        self::assertResponseRedirects('/app?responsable=ya-verificado');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $laura]));
    }

    public function test_nobody_can_ask_to_supervise_a_pool_they_do_not_work_in(): void
    {
        $client = $this->world();
        $this->signIn($client, 'otto');
        $client->request('POST', '/app/equipo/responsable/solicitar', ['_token' => $this->csrf($client), 'swapPoolIds' => [$this->uci]]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('pide a alguien de ese equipo que te invite', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $this->people['otto']['id']]));
        self::assertSame(0, $this->notifications('ana', 'supervisor_verification'));

        // A supervisor account with no job has nothing to ask for either.
        $this->signIn($client, 'laura');
        $client->request('POST', '/app/equipo/responsable/solicitar', ['_token' => $this->csrf($client), 'swapPoolIds' => [$this->uci]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_withdrawing_a_self_request_clears_the_checks_and_a_new_request_is_a_new_assignment(): void
    {
        $client = $this->world();
        $this->people['lauraWorker'] = $this->worker('Laura', $this->uci);
        $this->signIn($client, 'lauraWorker');
        $client->request('POST', '/app/equipo/responsable/solicitar', ['_token' => $this->csrf($client), 'swapPoolIds' => [$this->uci]]);
        $first = $this->scalar('SELECT id FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $this->people['lauraWorker']['id']]);
        $verificationPath = $this->scalar("SELECT target_url FROM notification_user_notifications WHERE recipient_id = :ana AND type = 'supervisor_verification'", ['ana' => $this->people['ana']['id']]);

        $this->signIn($client, 'ana');
        self::assertCount(1, $client->request('GET', '/app')->filter('[data-testid="supervisor-check-card"]'));

        $this->signIn($client, 'lauraWorker');
        $client->submit($client->request('GET', '/app/equipo/responsable/'.$first.'/dejar')->filter('form[action$="/dejar"]')->form());
        self::assertSame('left', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE id = :a', ['a' => $first]));
        self::assertSame(0, $this->notifications('ana', 'supervisor_team_update'), 'Withdrawing a pending request is not news for the team.');

        $this->signIn($client, 'ana');
        self::assertCount(0, $client->request('GET', '/app')->filter('[data-testid="supervisor-check-card"]'));
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        self::assertResponseStatusCodeSame(403);

        $this->signIn($client, 'lauraWorker');
        $client->request('POST', '/app/equipo/responsable/solicitar', ['_token' => $this->csrf($client), 'swapPoolIds' => [$this->uci]]);
        self::assertResponseRedirects('/app?responsable=solicitado');
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $this->people['lauraWorker']['id']]), 'The old request stays as history.');
    }

    public function test_a_self_request_in_a_team_too_small_stays_pending_and_says_why(): void
    {
        $client = $this->world();
        \assert(null !== $this->seed);
        $tiny = $this->seed->pool('Paritorio');
        $this->people['lauraWorker'] = $this->worker('Laura', $tiny);
        $this->people['sole'] = $this->worker('Sole', $tiny);

        $this->signIn($client, 'lauraWorker');
        $form = $client->request('GET', '/app/equipo/responsable/solicitar');
        self::assertStringContainsString('solo hay 1 persona que puede hacerlo', $form->html());
        $client->submit($form->filter('form[action="/app/equipo/responsable/solicitar"]')->form());
        $home = $client->followRedirect();

        self::assertStringContainsString('Actualmente no hay suficientes compañeros en Turnin para verificarte', $home->html());
        self::assertSelectorTextContains('[data-testid="supervisor-progress"]', '0 de 2 confirmaciones');
        self::assertSame('pending_verification', $this->scalar('SELECT status FROM workforce_supervisor_assignments WHERE supervisor_user_id = :id', ['id' => $this->people['lauraWorker']['id']]));
    }

    public function test_onboarding_ends_with_the_optional_question_about_the_teams_she_works_in(): void
    {
        $client = $this->world();
        $this->people['lauraWorker'] = $this->worker('Laura', $this->uci);
        $this->signIn($client, 'lauraWorker');

        $page = $client->request('GET', '/app/equipo/responsable/solicitar?desde=onboarding');
        self::assertStringContainsString('¿También coordinas a este equipo?', $page->html());
        self::assertCount(1, $page->filter('a[href="/app"]:contains("No, continuar")'));
        self::assertCount(1, $page->filter('input[type="hidden"][name="swapPoolIds[]"][value="'.$this->uci.'"]'), 'A single team is not asked for again.');

        // Somebody already verified or pending in every team is not asked.
        $client->submit($page->filter('form[action="/app/equipo/responsable/solicitar"]')->form());
        $client->request('GET', '/app/equipo/responsable/solicitar?desde=onboarding');
        self::assertResponseRedirects('/app');
    }

    private function world(): KernelBrowser
    {
        $client = static::createClient();
        $this->seed = new SwapWorldSeed($this->db());
        $this->uci = $this->seed->pool('UCI');
        $this->urgencias = $this->seed->pool('Urgencias');
        foreach ([$this->uci, $this->urgencias] as $pool) {
            $this->db()->insert('workforce_shift_exchange_policies', ['swap_pool_id' => $pool, 'requires_approval' => true, 'allows_coverage' => false, 'updated_at' => '2026-09-24T18:00:00+00:00'], ['requires_approval' => 'boolean', 'allows_coverage' => 'boolean']);
        }
        foreach (['ana' => 'Ana', 'david' => 'David', 'eva' => 'Eva'] as $key => $name) {
            $this->people[$key] = $this->seed->worker($name, $this->uci);
        }
        foreach (['otto' => 'Otto', 'pia' => 'Pía'] as $key => $name) {
            $this->people[$key] = $this->seed->worker($name, $this->urgencias);
        }
        $this->people['laura'] = $this->supervisorAccount();

        return $client;
    }

    /** @return array{id: string, assignment: string, email: string} */
    private function worker(string $name, string $pool): array
    {
        \assert(null !== $this->seed);

        return $this->seed->worker($name, $pool);
    }

    /**
     * A Google account with a name and no job: the plain supervisor case.
     *
     * @return array{id: string, assignment: string, email: string}
     */
    private function supervisorAccount(): array
    {
        $id = Uuid::v7()->toRfc4122();
        $email = 'laura.'.substr($id, -12).'@hospital.example';
        $this->extraUsers[] = $id;
        $this->db()->insert('identity_users', ['id' => $id, 'email' => $email, 'password_hash' => null, 'has_worker_profile' => false, 'has_supervisor_profile' => false, 'created_at' => '2026-09-10T00:00:00+00:00'], ['has_worker_profile' => 'boolean', 'has_supervisor_profile' => 'boolean']);
        $this->db()->insert('identity_personal_profiles', ['user_id' => $id, 'given_name' => 'Laura', 'family_name' => 'García', 'phone_encrypted' => null, 'identity_evidence' => null, 'created_at' => '2026-09-10T00:00:00+00:00', 'updated_at' => '2026-09-10T00:00:00+00:00']);

        return ['id' => $id, 'assignment' => '', 'email' => $email];
    }

    /** Publishes, proposes and accepts through HTTP; returns the proposal id. */
    private function pendingApproval(KernelBrowser $client, string $owner, string $proposer, ?string $pool = null, string $agreementDay = self::ANA_SHIFT, string $returnDay = self::DAVID_SHIFT): string
    {
        \assert(null !== $this->seed);
        $this->seed->shift($this->people[$owner]['assignment'], $agreementDay, 'Noche', 'N', '22:00', '08:00', 'night');
        $this->seed->shift($this->people[$proposer]['assignment'], $returnDay, 'Noche', 'N', '22:00', '08:00', 'night');

        $this->signIn($client, $owner);
        $page = $client->request('GET', '/app/changes');
        $token = (string) $page->filter('[data-changes-csrf-value]')->attr('data-changes-csrf-value');
        $client->request('POST', '/app/changes/publicar', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['assignmentId' => $this->people[$owner]['assignment'], 'date' => $agreementDay, 'swapPoolId' => $pool ?? ''], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $requestId = $this->scalar('SELECT id FROM swap_requests WHERE worker_id = :owner AND work_date = :day', ['owner' => $this->people[$owner]['id'], 'day' => $agreementDay]);

        $this->signIn($client, $proposer);
        $client->request('POST', '/app/changes/'.$requestId.'/propuestas', ['_token' => $token, 'offeredShifts' => [$this->people[$proposer]['assignment'].'|'.$returnDay]]);
        $proposalId = $this->scalar('SELECT id FROM swap_proposals WHERE request_id = :request', ['request' => $requestId]);
        $optionId = $this->scalar('SELECT id FROM swap_proposal_options WHERE proposal_id = :proposal', ['proposal' => $proposalId]);

        $this->signIn($client, $owner);
        $client->request('POST', '/app/changes/proposals/'.$proposalId.'/aceptar', ['_token' => $token, 'optionId' => $optionId]);
        self::assertSame('pending_approval', $this->proposalStatus($proposalId));

        return $proposalId;
    }

    private function verifiedLaura(KernelBrowser $client): string
    {
        $this->signIn($client, 'ana');
        $token = $this->invite($client, $this->uci);
        $this->signIn($client, 'laura');
        $client->submit($client->request('GET', '/invitacion/responsable/'.$token)->filter('form[action$="/aceptar"]')->form());
        $verificationPath = $this->scalar("SELECT target_url FROM notification_user_notifications WHERE recipient_id = :david AND type = 'supervisor_verification'", ['david' => $this->people['david']['id']]);
        $this->signIn($client, 'david');
        $client->request('POST', $verificationPath, ['_token' => $this->csrf($client), 'decision' => 'confirmed']);
        $assignmentId = $this->scalar("SELECT id FROM workforce_supervisor_assignments WHERE supervisor_user_id = :laura AND status = 'verified'", ['laura' => $this->people['laura']['id']]);

        return $assignmentId;
    }

    private function invite(KernelBrowser $client, string $pool): string
    {
        $client->request('POST', '/app/equipo/'.$pool.'/invitar-responsable', ['_token' => $this->csrf($client)]);
        self::assertResponseIsSuccessful();
        $url = (string) $client->getCrawler()->filter('[data-testid="supervisor-invitation-url"]')->attr('value');
        self::assertMatchesRegularExpression('#/invitacion/responsable/[A-Za-z0-9_-]{43}$#', $url);
        $message = (string) $client->getCrawler()->filter('[data-supervisor-share-message-value]')->attr('data-supervisor-share-message-value');
        self::assertStringContainsString($url, $message);
        self::assertStringContainsString('Solo tendrás que entrar con tu cuenta de Google.', $message);

        return substr($url, (int) strrpos($url, '/') + 1);
    }

    private function review(KernelBrowser $client, string $proposalId, string $decision): void
    {
        $page = $client->request('GET', '/app/responsable');
        $token = (string) $page->filter('[data-changes-csrf-value]')->attr('data-changes-csrf-value');
        $client->request('POST', '/app/responsable/cambios/'.$proposalId.'/'.$decision, ['_token' => $token]);
    }

    private function csrf(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/app/equipo')->filter('[data-supervision-csrf-value]')->attr('data-supervision-csrf-value');
    }

    /** @param array<string, mixed> $profile */
    private function fakeGoogle(KernelBrowser $client, array $profile): void
    {
        if (null !== $this->google) {
            $this->google->answerAs($profile);

            return;
        }
        $client->disableReboot();
        $this->google = new FakeGoogleProvider($profile);
        $requestStack = static::getContainer()->get(RequestStack::class);
        \assert($requestStack instanceof RequestStack);
        static::getContainer()->set('knpu.oauth2.client.google', new GoogleClient($this->google, $requestStack));
    }

    private function googleRoundTrip(KernelBrowser $client): void
    {
        $client->request('GET', '/auth/google');
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $parameters);
        $state = $parameters['state'] ?? '';
        self::assertIsString($state);
        $client->request('GET', '/auth/google/callback?code=fake&state='.$state);
    }

    private function signIn(KernelBrowser $client, string $who): void
    {
        $person = $this->people[$who];
        $client->loginUser(new SecurityUser($person['id'], $person['email'], null, '' !== $person['assignment'], 'laura' === $who));
    }

    private function proposalStatus(string $proposalId): string
    {
        return $this->scalar('SELECT status FROM swap_proposals WHERE id = :id', ['id' => $proposalId]);
    }

    private function notifications(string $who, string $type, ?string $title = null): int
    {
        return $this->rows('SELECT COUNT(*) FROM notification_user_notifications WHERE recipient_id = :who AND type = :type'.(null === $title ? '' : ' AND title = :title'), ['who' => $this->people[$who]['id'], 'type' => $type] + (null === $title ? [] : ['title' => $title]));
    }

    /** @param array<string, string> $parameters */
    private function rows(string $sql, array $parameters): int
    {
        $value = $this->db()->fetchOne($sql, $parameters);

        return is_numeric($value) ? (int) $value : -1;
    }

    /** @param array<string, string> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        $value = $this->db()->fetchOne($sql, $parameters);

        return \is_scalar($value) ? (string) $value : '';
    }

    private function db(): Connection
    {
        if (null === $this->connection) {
            $connection = static::getContainer()->get(Connection::class);
            \assert($connection instanceof Connection);
            $this->connection = $connection;
        }

        return $this->connection;
    }
}
