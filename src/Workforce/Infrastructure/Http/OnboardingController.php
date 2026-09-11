<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Http;

use App\Workforce\Application\Command\CompleteWorkerOnboarding;
use App\Workforce\Application\Command\SaveWorkerOnboardingDraft;
use App\Workforce\Application\Query\GetWorkerOnboardingDraft;
use App\Workforce\Domain\AuthenticatedWorker;
use App\Workforce\Domain\WorkerOnboardingDraft;
use App\Workforce\Domain\WorkerOnboardingIdentity;
use InvalidArgumentException;
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

final readonly class OnboardingController
{
    public function __construct(private MessageBusInterface $commandBus, private MessageBusInterface $queryBus, private Environment $twig, private TokenStorageInterface $tokens, private AuthenticatedWorker $workers, private WorkerOnboardingIdentity $identity, private CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/onboarding', name: 'workforce_onboarding', methods: ['GET'])]
    public function page(): Response
    {
        $workerId = $this->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }
        $profile = $this->identity->profileFor($workerId);
        $draft = $this->handled($this->queryBus, new GetWorkerOnboardingDraft($workerId));

        return new Response($this->twig->render('workforce/onboarding.html.twig', [
            'profile' => $profile,
            'draft' => $draft instanceof WorkerOnboardingDraft ? $draft : null,
        ]));
    }

    #[Route('/onboarding/name', name: 'workforce_onboarding_name', methods: ['POST'])]
    public function name(Request $request): JsonResponse
    {
        return $this->mutate($request, function (string $workerId) use ($request): void {
            $this->identity->rename($workerId, $request->request->getString('given_name'), $request->request->getString('family_name'));
        });
    }

    #[Route('/onboarding/identity', name: 'workforce_onboarding_identity', methods: ['POST'])]
    public function identity(Request $request): JsonResponse
    {
        return $this->mutate($request, function (string $workerId) use ($request): void {
            $this->identity->protect($workerId, $request->request->getString('identity_document'), $request->request->getString('phone'));
        });
    }

    #[Route('/onboarding/progress', name: 'workforce_onboarding_progress', methods: ['POST'])]
    public function progress(Request $request): JsonResponse
    {
        return $this->mutate($request, function (string $workerId) use ($request): array {
            $draft = $this->handled($this->commandBus, new SaveWorkerOnboardingDraft($workerId, $request->request->getString('workplace_id') ?: null, $request->request->getString('staff_category_id') ?: null, $request->request->getString('primary_destination_id') ?: null, $this->strings($request->request->all('additional_destination_ids'))));
            if (!$draft instanceof WorkerOnboardingDraft) {
                throw new InvalidArgumentException('No se pudo guardar el progreso.');
            }

            return ['primaryDestinationId' => $draft->primaryDestination?->selectionId, 'additionalDestinationIds' => $draft->additionalDestinationIds()];
        });
    }

    #[Route('/onboarding/complete', name: 'workforce_onboarding_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        return $this->mutate($request, function (string $workerId): array {
            $profile = $this->identity->profileFor($workerId);
            $draft = $this->handled($this->queryBus, new GetWorkerOnboardingDraft($workerId));
            if (null === $profile || !$profile->identityProvided || !$draft instanceof WorkerOnboardingDraft || null === $draft->workplaceId || null === $draft->staffCategoryId || null === $draft->primaryDestination) {
                throw new InvalidArgumentException('Completa todos los pasos antes de entrar en Turnin.');
            }
            $this->handled($this->commandBus, new CompleteWorkerOnboarding($workerId, $draft->workplaceId, $draft->staffCategoryId, $draft->primaryDestination->selectionId, $draft->additionalDestinationIds()));

            return ['redirect' => '/app'];
        });
    }

    private function mutate(Request $request, callable $operation): JsonResponse
    {
        $workerId = $this->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('onboarding', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return new JsonResponse(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
        }
        try {
            $result = $operation($workerId);

            return new JsonResponse(['ok' => true, 'result' => $result]);
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function workerId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof UserInterface ? $this->workers->idForEmail($user->getUserIdentifier()) : null;
    }

    private function handled(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    /** @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }
}
