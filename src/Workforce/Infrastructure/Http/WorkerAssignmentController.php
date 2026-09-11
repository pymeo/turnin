<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Http;

use App\Workforce\Application\Command\DeactivateWorkerAssignment;
use App\Workforce\Application\Command\SetPrimaryWorkerAssignment;
use App\Workforce\Application\Query\GetWorkerAssignments;
use App\Workforce\Domain\AuthenticatedWorker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;
use Twig\Environment;

final readonly class WorkerAssignmentController
{
    public function __construct(private MessageBusInterface $commandBus, private MessageBusInterface $queryBus, private Environment $twig, private TokenStorageInterface $tokens, private AuthenticatedWorker $workers, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/app/workplaces', name: 'workforce_assignments', methods: ['GET'])]
    public function page(): Response
    {
        $workerId = $this->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }
        $assignments = $this->queryBus->dispatch(new GetWorkerAssignments($workerId))->last(HandledStamp::class)?->getResult();

        return new Response($this->twig->render('workforce/assignments.html.twig', ['assignments' => \is_array($assignments) ? $assignments : [], 'csrfToken' => $this->csrf->getToken('workforce_assignments')->getValue()]));
    }

    #[Route('/app/workplaces/{assignmentId}/primary', name: 'workforce_assignment_primary', methods: ['POST'])]
    public function primary(Request $request, string $assignmentId): JsonResponse
    {
        return $this->mutate($request, fn (string $workerId): object => $this->commandBus->dispatch(new SetPrimaryWorkerAssignment($workerId, $assignmentId)));
    }

    #[Route('/app/workplaces/{assignmentId}/deactivate', name: 'workforce_assignment_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, string $assignmentId): JsonResponse
    {
        return $this->mutate($request, fn (string $workerId): object => $this->commandBus->dispatch(new DeactivateWorkerAssignment($workerId, $assignmentId)));
    }

    private function mutate(Request $request, callable $operation): JsonResponse
    {
        $workerId = $this->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('workforce_assignments', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return new JsonResponse(['error' => 'La sesión ha caducado.'], 419);
        }
        try {
            $operation($workerId);

            return new JsonResponse(['ok' => true]);
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function workerId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof UserInterface ? $this->workers->idForEmail($user->getUserIdentifier()) : null;
    }
}
