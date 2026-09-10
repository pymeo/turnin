<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Http;

use App\Workforce\Application\Command\CompleteWorkerOnboarding;
use App\Workforce\Domain\AuthenticatedWorker;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;
use Twig\Environment;

final class OnboardingController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus, private Environment $twig, private TokenStorageInterface $tokens, private AuthenticatedWorker $workers, private CsrfTokenManagerInterface $csrf)
    {
        $this->messageBus = $commandBus;
    }

    #[Route('/onboarding', name: 'workforce_onboarding', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return new Response('', Response::HTTP_FOUND, ['Location' => '/login']);
        }
        $workerId = $this->workers->idForEmail($user->getUserIdentifier());
        if (null === $workerId) {
            return new Response('', Response::HTTP_FOUND, ['Location' => '/login']);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                if (!$this->csrf->isTokenValid(new CsrfToken('onboarding', $request->request->getString('_token')))) {
                    throw new InvalidArgumentException('La sesión del formulario ha caducado.');
                }
                $this->handle(new CompleteWorkerOnboarding($workerId, $request->request->getString('workplace_id'), $request->request->getString('staff_category_id'), $request->request->getString('specialty_id') ?: null, $request->request->getString('organizational_unit_id') ?: null, $request->request->getString('functional_area') ?: 'General'));

                return new Response('', Response::HTTP_FOUND, ['Location' => '/app']);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('workforce/onboarding.html.twig', ['error' => $error]));
    }
}
