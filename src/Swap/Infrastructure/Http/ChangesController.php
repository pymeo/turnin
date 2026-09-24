<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Http;

use App\Swap\Application\Command\AcceptSwapProposal;
use App\Swap\Application\Command\CancelSwapRequest;
use App\Swap\Application\Command\ChangeAvailabilityDay;
use App\Swap\Application\Command\CreateSwapProposal;
use App\Swap\Application\Command\DecideSwapProposal;
use App\Swap\Application\Command\DeclareAvailability;
use App\Swap\Application\Command\OpenSwapRequest;
use App\Swap\Application\Command\WithdrawAvailability;
use App\Swap\Application\Query\ChangesSetupView;
use App\Swap\Application\Query\DayExchangeView;
use App\Swap\Application\Query\FindRestBlockOpportunities;
use App\Swap\Application\Query\GetChangesSetup;
use App\Swap\Application\Query\GetDayExchange;
use App\Swap\Application\Query\GetMonthExchangeMarks;
use App\Swap\Application\Query\GetMyAvailability;
use App\Swap\Application\Query\GetMySwapRequests;
use App\Swap\Application\Query\GetOpenSwapRequests;
use App\Swap\Application\Query\GetSwapComposerCalendar;
use App\Swap\Application\Query\GetSwapGroups;
use App\Swap\Application\Query\GetSwapProposalBoard;
use App\Swap\Application\Query\GetSwapProposalDecision;
use App\Swap\Application\Query\MonthExchangeMarksView;
use App\Swap\Application\Query\SwapComposerCalendarView;
use App\Swap\Application\Query\SwapProposalDecisionView;
use App\Swap\Application\SwapAccessDenied;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use Twig\Environment;

/**
 * "Cambios": I want to give a shift away, and these are the ones my colleagues
 * want to give away.
 *
 * Thin on purpose. Which pools a worker may see is decided in SwapWorkspace,
 * not here, so there is no path through this class that can widen it.
 */
final readonly class ChangesController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private Environment $twig,
        private SwapSession $session,
    ) {
    }

    #[Route('/app/changes', name: 'swap_changes', methods: ['GET'])]
    public function changes(): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        try {
            $groups = $this->session->handled($this->queryBus, new GetSwapGroups($workerId));
            $open = $this->session->handled($this->queryBus, new GetOpenSwapRequests($workerId));
            $setup = $this->session->handled($this->queryBus, new GetChangesSetup($workerId));
        } catch (Throwable $exception) {
            if (SwapSession::rootCause($exception) instanceof SwapAccessDenied) {
                return new RedirectResponse('/onboarding');
            }

            throw $exception;
        }

        return new Response($this->twig->render('swap/changes.html.twig', [
            'groups' => $groups,
            'openRequests' => $open,
            'myRequests' => $this->session->handled($this->queryBus, new GetMySwapRequests($workerId)),
            'proposals' => $this->session->handled($this->queryBus, new GetSwapProposalBoard($workerId)),
            'myAvailability' => $this->session->handled($this->queryBus, new GetMyAvailability($workerId)),
            'setup' => $setup instanceof ChangesSetupView ? $setup : new ChangesSetupView([], [], '', ''),
            'csrfToken' => $this->session->token(),
        ]));
    }

    /** "Mis turnos publicados": what I am trying to give away, and who answered. */
    #[Route('/app/changes/mine', name: 'swap_changes_mine', methods: ['GET'])]
    public function mine(): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        return new Response($this->twig->render('swap/activity.html.twig', [
            'groups' => $this->session->handled($this->queryBus, new GetSwapGroups($workerId)),
            'myRequests' => $this->session->handled($this->queryBus, new GetMySwapRequests($workerId)),
            'proposals' => $this->session->handled($this->queryBus, new GetSwapProposalBoard($workerId)),
            'myAvailability' => $this->session->handled($this->queryBus, new GetMyAvailability($workerId)),
            'csrfToken' => $this->session->token(),
        ]));
    }

    /** "Turnos de compañeros", with the verdict on each one already decided. */
    #[Route('/app/changes/available', name: 'swap_changes_available', methods: ['GET'])]
    public function available(): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        return new Response($this->twig->render('swap/available.html.twig', [
            'openRequests' => $this->session->handled($this->queryBus, new GetOpenSwapRequests($workerId)),
            'csrfToken' => $this->session->token(),
        ]));
    }

    /** "Quiero librar este turno", from the calendar's day sheet or the guide. */
    #[Route('/app/changes/publicar', name: 'swap_request_open', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
    {
        return $this->session->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->session->payload($request);
            $requestId = $this->session->handled($this->commandBus, new OpenSwapRequest(
                $workerId,
                $this->session->text($payload, 'assignmentId'),
                $this->session->text($payload, 'date'),
                $this->session->text($payload, 'swapPoolId') ?: null,
            ));

            return ['requestId' => \is_string($requestId) ? $requestId : null];
        });
    }

    #[Route('/app/changes/{requestId}/retirar', name: 'swap_request_cancel', methods: ['POST'])]
    public function cancel(Request $request, string $requestId): JsonResponse
    {
        return $this->session->respond($request, function (string $workerId) use ($requestId): array {
            $this->commandBus->dispatch(new CancelSwapRequest($workerId, $requestId));

            return [];
        });
    }

    /**
     * "Se lo hago": my own calendar, with the shifts this colleague could do.
     *
     * The week being shown and the shifts already ticked travel in the query
     * string so the page works with nothing but a browser.
     */
    #[Route('/app/changes/{requestId}/intercambio', name: 'swap_proposal_new_exchange', methods: ['GET'])]
    public function newExchange(Request $request, string $requestId): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        try {
            $calendar = $this->session->handled($this->queryBus, new GetSwapComposerCalendar(
                $workerId,
                $requestId,
                $request->query->getInt('semana'),
                array_values(array_filter($request->query->all('turnos'), static fn (mixed $value): bool => \is_string($value) && '' !== trim($value))),
            ));
        } catch (Throwable $exception) {
            $cause = SwapSession::rootCause($exception);
            if ($cause instanceof SwapAccessDenied || $cause instanceof InvalidArgumentException) {
                return new Response('Este turno ya no está disponible.', Response::HTTP_NOT_FOUND);
            }

            throw $exception;
        }
        if (!$calendar instanceof SwapComposerCalendarView) {
            return new Response('Este turno ya no está disponible.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('swap/exchange_composer.html.twig', [
            'calendar' => $calendar,
            'csrfToken' => $this->session->token(),
        ]));
    }

    /** "Enviar N opciones": one proposal carrying one to five return shifts. */
    #[Route('/app/changes/{requestId}/propuestas', name: 'swap_proposal_create', methods: ['POST'])]
    public function createProposal(Request $request, string $requestId): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }
        if (!$this->session->isValid($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        try {
            $offered = array_values(array_filter($request->request->all('offeredShifts'), static fn (mixed $value): bool => \is_string($value) && '' !== trim($value)));
            $this->session->handled($this->commandBus, new CreateSwapProposal($workerId, $requestId, $offered));
        } catch (Throwable $exception) {
            return new Response(SwapSession::rootCause($exception)->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse('/app/changes/proposals?enviada=1');
    }

    /** "Mis intercambios": everything proposed to me and by me. */
    #[Route('/app/changes/proposals', name: 'swap_proposals', methods: ['GET'])]
    public function proposals(): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        return new Response($this->twig->render('swap/proposals.html.twig', [
            'proposals' => $this->session->handled($this->queryBus, new GetSwapProposalBoard($workerId)),
            'csrfToken' => $this->session->token(),
        ]));
    }

    /** "¿Cuál de estos le puedes hacer tú?" */
    #[Route('/app/changes/proposals/{proposalId}', name: 'swap_proposal_decision', methods: ['GET'])]
    public function proposalDecision(string $proposalId): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        try {
            $decision = $this->session->handled($this->queryBus, new GetSwapProposalDecision($workerId, $proposalId));
        } catch (Throwable $exception) {
            $cause = SwapSession::rootCause($exception);
            if ($cause instanceof SwapAccessDenied || $cause instanceof InvalidArgumentException) {
                return new Response('Esa propuesta ya no está disponible.', Response::HTTP_NOT_FOUND);
            }

            throw $exception;
        }
        if (!$decision instanceof SwapProposalDecisionView) {
            return new Response('Esa propuesta ya no está disponible.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('swap/proposal_decision.html.twig', [
            'decision' => $decision,
            'csrfToken' => $this->session->token(),
        ]));
    }

    #[Route('/app/changes/proposals/{proposalId}/aceptar', name: 'swap_proposal_accept', methods: ['POST'])]
    public function acceptProposal(Request $request, string $proposalId): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }
        if (!$this->session->isValid($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        try {
            $this->session->handled($this->commandBus, new AcceptSwapProposal($workerId, $proposalId, $request->request->getString('optionId')));
        } catch (Throwable $exception) {
            return new Response(SwapSession::rootCause($exception)->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse('/app/changes/proposals?hecho=1');
    }

    #[Route('/app/changes/proposals/{proposalId}/{decision}', name: 'swap_proposal_decide', requirements: ['decision' => 'reject|withdraw'], methods: ['POST'])]
    public function decideProposal(Request $request, string $proposalId, string $decision): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }
        if (!$this->session->isValid($request)) {
            return new Response('La sesión ha caducado.', 419);
        }
        try {
            $this->session->handled($this->commandBus, new DecideSwapProposal($workerId, $proposalId, $decision));
        } catch (Throwable $exception) {
            return new Response(SwapSession::rootCause($exception)->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse('/app/changes/proposals');
    }

    #[Route('/app/changes/bridge', name: 'swap_bridge_finder', methods: ['GET'])]
    public function bridgeFinder(): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        return new Response($this->twig->render('swap/bridges.html.twig', ['opportunities' => $this->session->handled($this->queryBus, new FindRestBlockOpportunities($workerId))]));
    }

    /** "Puedo trabajar este día", optionally narrowed to some of my groups. */
    #[Route('/app/changes/disponible', name: 'swap_availability_declare', methods: ['POST'])]
    public function declare(Request $request): JsonResponse
    {
        return $this->session->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->session->payload($request);
            $dates = $this->session->strings($payload, 'dates');
            if ([] === $dates) {
                $date = $this->session->text($payload, 'date');
                $dates = '' === $date ? [] : [$date];
            }
            $kinds = $this->session->strings($payload, 'shiftKinds');
            if ([] === $kinds) {
                $kinds = ['morning', 'evening', 'night'];
            }
            $pools = $this->session->strings($payload, 'swapPoolIds');
            foreach ($dates as $date) {
                // The guided editor always sends the selected pools. Keep the
                // contextual/legacy entry point useful by resolving an empty
                // selection from the worker-owned assignment server-side.
                if ([] === $pools) {
                    $this->session->handled($this->commandBus, new DeclareAvailability(
                        $workerId,
                        $this->session->text($payload, 'assignmentId'),
                        $date,
                        [],
                        $kinds,
                    ));
                    continue;
                }
                $this->session->handled($this->commandBus, new ChangeAvailabilityDay($workerId, $date, $pools, $kinds));
            }

            return ['dates' => $dates, 'pools' => $pools];
        });
    }

    #[Route('/app/changes/disponibilidad/retirar', name: 'swap_availability_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): JsonResponse
    {
        return $this->session->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->session->payload($request);
            $this->commandBus->dispatch(new WithdrawAvailability(
                $workerId,
                $this->session->text($payload, 'availabilityId') ?: null,
                $this->session->text($payload, 'date') ?: null,
                $this->session->strings($payload, 'swapPoolIds'),
            ));

            return [];
        });
    }

    /** Which cells of a month carry a badge. */
    #[Route('/app/changes/mes', name: 'swap_month_marks', methods: ['GET'])]
    public function monthMarks(Request $request): JsonResponse
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $marks = $this->session->handled($this->queryBus, new GetMonthExchangeMarks(
                $workerId,
                $request->query->getString('assignment'),
                $request->query->getString('month'),
            ));
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => SwapSession::rootCause($exception)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true, 'result' => $marks instanceof MonthExchangeMarksView ? $marks : null]);
    }

    /** The exchange half of a calendar day, for the day sheet. */
    #[Route('/app/changes/dia', name: 'swap_day_exchange', methods: ['GET'])]
    public function day(Request $request): JsonResponse
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $view = $this->session->handled($this->queryBus, new GetDayExchange(
                $workerId,
                $request->query->getString('assignment'),
                $request->query->getString('date'),
            ));
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => SwapSession::rootCause($exception)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true, 'result' => $view instanceof DayExchangeView ? $view : null]);
    }
}
