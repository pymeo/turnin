<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Http;

use App\Workforce\Application\Command\AcceptSupervisorInvitation;
use App\Workforce\Application\Command\DeclineSupervisorInvitation;
use App\Workforce\Application\Command\InviteSupervisor;
use App\Workforce\Application\Command\LeaveSupervision;
use App\Workforce\Application\Command\SupervisorInvitationCreated;
use App\Workforce\Application\Command\VerifySupervisor;
use App\Workforce\Application\Query\GetSupervisionOverview;
use App\Workforce\Application\Query\GetSupervisorInvitation;
use App\Workforce\Application\Query\GetSupervisorVerification;
use App\Workforce\Application\Query\SupervisionOverview;
use App\Workforce\Application\Query\SupervisorInvitationView;
use App\Workforce\Application\Query\SupervisorVerificationView;
use App\Workforce\Application\Supervision\SupervisionShareMessages;
use App\Workforce\Domain\AuthenticatedWorker;
use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorVerificationDecision;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Throwable;
use Twig\Environment;

/**
 * Supervisor onboarding: a colleague invites, the supervisor accepts, the team
 * vouches. See docs/adr/0014-team-verified-supervisors.md.
 *
 * Every identity comes from the session. The tokens in these URLs only say
 * *which* invitation or request is meant; whether this person may act on it is
 * decided again by the handlers, never by having the link.
 */
final readonly class SupervisionController
{
    use TargetPathTrait;

    private const string CSRF = 'supervision';

    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private Environment $twig,
        private TokenStorageInterface $tokens,
        private AuthenticatedWorker $workers,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/app/equipo', name: 'workforce_team', methods: ['GET'])]
    public function team(Request $request): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }

        return new Response($this->twig->render('workforce/team.html.twig', [
            'overview' => $this->overview($userId, $request),
            'csrfToken' => $this->token(),
        ]));
    }

    #[Route('/app/equipo/{swapPoolId}/invitar-responsable', name: 'workforce_supervisor_invite', requirements: ['swapPoolId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function invite(Request $request, string $swapPoolId): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado. Vuelve atrás y recarga la página.', 419);
        }
        try {
            $created = $this->handled($this->commandBus, new InviteSupervisor($userId, $swapPoolId));
        } catch (Throwable $exception) {
            return $this->rejected($exception, Response::HTTP_FORBIDDEN);
        }
        \assert($created instanceof SupervisorInvitationCreated);
        $url = $request->getSchemeAndHttpHost().$created->path;

        return new Response($this->twig->render('workforce/supervisor_invitation_created.html.twig', [
            'invitation' => $created,
            'url' => $url,
            'message' => SupervisionShareMessages::invitation($created->teamLabel, $created->workplaceName, $url),
        ]));
    }

    /**
     * The link a colleague sends. Anonymous visitors see the team and the
     * Google button; the session remembers this URL so they come straight back
     * here after OAuth instead of landing on a generic home.
     */
    #[Route('/invitacion/responsable/{token}', name: 'workforce_supervisor_invitation', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
    public function invitation(Request $request, string $token): Response
    {
        $userId = $this->userId();
        $invitation = $this->handled($this->queryBus, new GetSupervisorInvitation($token, $userId));
        if (!$invitation instanceof SupervisorInvitationView) {
            return new Response($this->twig->render('workforce/supervisor_link_unavailable.html.twig', ['message' => 'Esta invitación no existe o ya no está disponible.']), Response::HTTP_NOT_FOUND);
        }
        if (null === $userId) {
            if ($request->hasSession()) {
                $this->saveTargetPath($request->getSession(), 'main', SupervisionShareMessages::invitationPath($token));
            }

            return new Response($this->twig->render('workforce/supervisor_invitation_landing.html.twig', ['invitation' => $invitation]));
        }

        return new Response($this->twig->render('workforce/supervisor_invitation.html.twig', [
            'invitation' => $invitation,
            'token' => $token,
            'csrfToken' => $this->token(),
        ]));
    }

    #[Route('/invitacion/responsable/{token}/{answer}', name: 'workforce_supervisor_invitation_answer', requirements: ['token' => '[A-Za-z0-9_-]{43}', 'answer' => 'aceptar|rechazar'], methods: ['POST'])]
    public function answerInvitation(Request $request, string $token, string $answer): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse(SupervisionShareMessages::invitationPath($token));
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado. Vuelve atrás y recarga la página.', 419);
        }
        try {
            $this->handled($this->commandBus, 'aceptar' === $answer ? new AcceptSupervisorInvitation($userId, $token) : new DeclineSupervisorInvitation($userId, $token));
        } catch (Throwable $exception) {
            return $this->rejected($exception, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse('aceptar' === $answer ? '/app' : '/app?responsable=rechazado');
    }

    #[Route('/app/equipo/responsable/verificar/{token}', name: 'workforce_supervisor_verification', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
    public function verification(string $token): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        $view = $this->handled($this->queryBus, new GetSupervisorVerification($userId, $token));
        \assert($view instanceof SupervisorVerificationView);

        return new Response($this->twig->render('workforce/supervisor_verification.html.twig', [
            'verification' => $view,
            'token' => $token,
            'csrfToken' => $this->token(),
        ]), SupervisorVerificationView::FORBIDDEN === $view->state ? Response::HTTP_FORBIDDEN : Response::HTTP_OK);
    }

    #[Route('/app/equipo/responsable/verificar/{token}', name: 'workforce_supervisor_verify', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['POST'])]
    public function verify(Request $request, string $token): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado. Vuelve atrás y recarga la página.', 419);
        }
        $decision = SupervisorVerificationDecision::tryFrom($request->request->getString('decision'));
        if (null === $decision) {
            return new Response('Elige una respuesta.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->handled($this->commandBus, new VerifySupervisor($userId, $token, $decision));
        } catch (Throwable $exception) {
            return $this->rejected($exception, Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse(SupervisionShareMessages::verificationPath($token));
    }

    /** Stepping down is never one tap: this page is the confirmation. */
    #[Route('/app/equipo/responsable/{assignmentId}/dejar', name: 'workforce_supervisor_leave_confirm', requirements: ['assignmentId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function confirmLeave(Request $request, string $assignmentId): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        foreach ($this->overview($userId, $request)->mySupervisions as $supervision) {
            if ($supervision->assignmentId === $assignmentId) {
                return new Response($this->twig->render('workforce/supervisor_leave.html.twig', ['supervision' => $supervision, 'csrfToken' => $this->token()]));
            }
        }

        return new Response($this->twig->render('workforce/supervisor_link_unavailable.html.twig', ['message' => 'Esta solicitud de responsable ya no está activa.']), Response::HTTP_NOT_FOUND);
    }

    #[Route('/app/equipo/responsable/{assignmentId}/dejar', name: 'workforce_supervisor_leave', requirements: ['assignmentId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function leave(Request $request, string $assignmentId): Response
    {
        $userId = $this->userId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado. Vuelve atrás y recarga la página.', 419);
        }
        try {
            $this->handled($this->commandBus, new LeaveSupervision($userId, $assignmentId));
        } catch (Throwable $exception) {
            return $this->rejected($exception, Response::HTTP_NOT_FOUND);
        }

        return new RedirectResponse('/app?responsable=dejado');
    }

    private function overview(string $userId, Request $request): SupervisionOverview
    {
        $overview = $this->handled($this->queryBus, new GetSupervisionOverview($userId, $request->getSchemeAndHttpHost()));
        \assert($overview instanceof SupervisionOverview);

        return $overview;
    }

    private function rejected(Throwable $exception, int $status): Response
    {
        $cause = $exception;
        while ($cause instanceof HandlerFailedException && null !== $cause->getPrevious()) {
            $cause = $cause->getPrevious();
        }
        if (!$cause instanceof SupervisionRejected) {
            throw $exception;
        }

        return new Response($this->twig->render('workforce/supervisor_link_unavailable.html.twig', ['message' => $cause->getMessage()]), $status);
    }

    private function userId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof UserInterface ? $this->workers->idForEmail($user->getUserIdentifier()) : null;
    }

    private function token(): string
    {
        return $this->csrf->getToken(self::CSRF)->getValue();
    }

    private function validCsrf(Request $request): bool
    {
        return $this->csrf->isTokenValid(new CsrfToken(self::CSRF, $request->request->getString('_token')));
    }

    private function handled(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
