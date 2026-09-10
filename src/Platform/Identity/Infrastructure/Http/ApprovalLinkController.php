<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class ApprovalLinkController
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    #[Route('/approval/{token}', name: 'identity_approval_link', requirements: ['token' => '[A-Za-z0-9_-]{8,256}'], methods: ['GET'])]
    public function __invoke(Request $request, string $token): Response
    {
        $tokenObject = $this->tokens->getToken();
        $authenticatedUser = $tokenObject?->getUser();
        if (null === $tokenObject || !\is_object($authenticatedUser)) {
            $target = '/approval/'.$token;
            $request->getSession()->set('_security.main.target_path', $target);

            return new RedirectResponse('/login');
        }

        return new Response('El enlace de aprobación está preparado para la siguiente fase.', Response::HTTP_NOT_IMPLEMENTED);
    }
}
