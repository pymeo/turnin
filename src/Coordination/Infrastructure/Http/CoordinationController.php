<?php

declare(strict_types=1);

namespace App\Coordination\Infrastructure\Http;

use App\Coordination\Application\Command\AcceptScheduleInvitation;
use App\Coordination\Application\Command\CreateScheduleInvitation;
use App\Coordination\Application\Command\RequestCoordinationDays;
use App\Coordination\Application\Command\RevokeScheduleLink;
use App\Coordination\Application\Command\ScheduleInvitationCreated;
use App\Coordination\Application\Query\CurrentCoordinationUser;
use App\Coordination\Application\Query\GetScheduleCoordination;
use App\Coordination\Application\Query\GetScheduleInvitation;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;
use Twig\Environment;

final readonly class CoordinationController
{
    public function __construct(private MessageBusInterface $commandBus, private MessageBusInterface $queryBus, private CurrentCoordinationUser $user, private CsrfTokenManagerInterface $csrf, private Environment $twig)
    {
    }

    #[Route('/app/together', name: 'coordination_together', methods: ['GET'])]
    public function together(Request $request): Response
    {
        $userId = $this->user->id();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        $month = preg_match('/^\d{4}-\d{2}$/', $request->query->getString('month')) ? $request->query->getString('month') : (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m');
        $from = new DateTimeImmutable($month.'-01 00:00:00', new DateTimeZone('Europe/Madrid'));
        $view = null;
        try {
            $view = $this->handled($this->queryBus, new GetScheduleCoordination($userId, $from, $from->add(new DateInterval('P1M'))));
        } catch (Throwable) {
        }

        return new Response($this->twig->render('coordination/together.html.twig', ['coordination' => $view, 'month' => $month]));
    }

    #[Route('/app/together/invite', name: 'coordination_invite', methods: ['POST'])]
    public function invite(Request $request): Response
    {
        $userId = $this->user->id();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        /** @var ScheduleInvitationCreated $created */
        $created = $this->handled($this->commandBus, new CreateScheduleInvitation($userId));
        $url = $request->getSchemeAndHttpHost().'/app/together/join/'.$created->token;

        return new Response($this->twig->render('coordination/invitation_created.html.twig', ['url' => $url, 'expiresAt' => $created->expiresAt]));
    }

    #[Route('/app/together/join/{token}', name: 'coordination_join', methods: ['GET'])]
    public function join(string $token): Response
    {
        if (null === $this->user->id()) {
            return new RedirectResponse('/login');
        }
        try {
            $invitation = $this->handled($this->queryBus, new GetScheduleInvitation($token));
        } catch (Throwable) {
            return new Response('Esta invitación no existe o ya no está disponible.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('coordination/join.html.twig', ['invitation' => $invitation, 'token' => $token]));
    }

    #[Route('/app/together/join/{token}', name: 'coordination_accept', methods: ['POST'])]
    public function accept(Request $request, string $token): Response
    {
        $userId = $this->user->id();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        try {
            $this->handled($this->commandBus, new AcceptScheduleInvitation($userId, $token));
        } catch (Throwable) {
            return new Response('Esta invitación no existe, ha caducado o ya fue utilizada.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse('/app/together');
    }

    #[Route('/app/together/unlink', name: 'coordination_unlink', methods: ['POST'])]
    public function unlink(Request $request): Response
    {
        $userId = $this->user->id();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        $this->handled($this->commandBus, new RevokeScheduleLink($userId));

        return new RedirectResponse('/app/together');
    }

    #[Route('/app/together/requests', name: 'coordination_requests', methods: ['POST'])]
    public function requestDays(Request $request): Response
    {
        $userId = $this->user->id();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->validCsrf($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        $month = preg_match('/^\d{4}-\d{2}$/', $request->request->getString('month')) ? $request->request->getString('month') : '';
        if ('' === $month) {
            return new Response('El mes no es válido.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $from = new DateTimeImmutable($month.'-01 00:00:00', new DateTimeZone('Europe/Madrid'));
        $days = $request->request->all('days');
        $selected = array_values(array_filter($days, 'is_string'));
        $this->handled($this->commandBus, new RequestCoordinationDays($userId, $from, $from->add(new DateInterval('P1M')), $selected));

        return new RedirectResponse('/app/together?month='.$month.'&requested='.\count($selected));
    }

    private function handled(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    private function validCsrf(Request $request): bool
    {
        return $this->csrf->isTokenValid(new CsrfToken('coordination', $request->request->getString('_token')));
    }
}
