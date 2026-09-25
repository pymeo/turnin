<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Http;

use App\Swap\Application\Command\ReviewSwapApproval;
use App\Swap\Application\Query\GetSupervisorDashboard;
use App\Swap\Application\SwapAccessDenied;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use Twig\Environment;

/**
 * "Área de responsable": agreements of the pools this person supervises.
 *
 * Both routes re-check authority in the Application layer on every request —
 * a VERIFIED assignment for the pool of that exact agreement. Having reached
 * this page, a link, or an older session proves nothing.
 */
final readonly class SupervisorApprovalController
{
    public function __construct(private MessageBusInterface $commandBus, private MessageBusInterface $queryBus, private Environment $twig, private SwapSession $session)
    {
    }

    #[Route('/app/responsable', name: 'swap_supervisor', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $userId = $this->session->workerId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        try {
            $dashboard = $this->session->handled($this->queryBus, new GetSupervisorDashboard($userId));
        } catch (Throwable $exception) {
            if (SwapSession::rootCause($exception) instanceof SwapAccessDenied) {
                return new Response($this->twig->render('swap/supervisor_forbidden.html.twig', ['csrfToken' => $this->session->token()]), Response::HTTP_FORBIDDEN);
            }

            throw $exception;
        }

        return new Response($this->twig->render('swap/supervisor.html.twig', [
            'dashboard' => $dashboard,
            'csrfToken' => $this->session->token(),
            'outcome' => $request->query->getString('hecho'),
        ]));
    }

    #[Route('/app/responsable/cambios/{proposalId}/{decision}', name: 'swap_supervisor_review', requirements: ['decision' => 'approve|reject'], methods: ['POST'])]
    public function review(Request $request, string $proposalId, string $decision): Response
    {
        $userId = $this->session->workerId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        if (!$this->session->isValid($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        try {
            $this->session->handled($this->commandBus, new ReviewSwapApproval($userId, $proposalId, $decision));
        } catch (Throwable $exception) {
            $cause = SwapSession::rootCause($exception);
            if ($cause instanceof SwapAccessDenied) {
                return new Response($cause->getMessage(), Response::HTTP_FORBIDDEN);
            }

            return new Response($cause->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse('/app/responsable?hecho='.('approve' === $decision ? 'aprobado' : 'rechazado'));
    }
}
