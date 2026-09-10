<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Http;

use App\Platform\Identity\Domain\PostAuthenticationDestinationResolver;
use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

final readonly class AppController
{
    public function __construct(private TokenStorageInterface $tokens, private Environment $twig, private PostAuthenticationDestinationResolver $destination)
    {
    }

    #[Route('/app', name: 'identity_app', methods: ['GET'])]
    public function app(): Response
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof SecurityUser) {
            return new RedirectResponse('/login');
        }

        $destination = $this->destination->resolve($user->hasWorkerProfile(), $user->hasSupervisorProfile());
        if ('/app' !== $destination) {
            return new RedirectResponse($destination);
        }

        return new Response($this->twig->render('identity/app.html.twig', ['user' => $user]));
    }

    #[Route('/supervisor', name: 'identity_supervisor', methods: ['GET'])]
    public function supervisor(): Response
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof SecurityUser || !$user->hasSupervisorProfile()) {
            return new Response('No tienes acceso a gestión.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('identity/supervisor.html.twig'));
    }
}
