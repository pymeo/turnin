<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Http;

use App\Platform\Identity\Application\Command\RegisterUser;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class IdentityController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus, private Environment $twig, private CsrfTokenManagerInterface $csrf)
    {
        $this->messageBus = $commandBus;
    }

    #[Route('/login', name: 'identity_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $lastEmail = $request->getSession()->get('_security.last_username', '');
        $authenticationError = $request->getSession()->remove('identity.authentication_error');

        return new Response($this->twig->render('identity/login.html.twig', ['last_email' => \is_string($lastEmail) ? $lastEmail : '', 'authentication_error' => \is_string($authenticationError) ? $authenticationError : null]));
    }

    #[Route('/register', name: 'identity_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('register', $request->request->getString('_token')))) {
                $error = 'La sesión del formulario ha caducado. Vuelve a intentarlo.';
            } elseif ((string) $request->request->get('password') !== (string) $request->request->get('password_repeat')) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                try {
                    $this->handle(new RegisterUser((string) $request->request->get('email'), (string) $request->request->get('password')));

                    return new Response('', Response::HTTP_FOUND, ['Location' => '/login']);
                } catch (InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return new Response($this->twig->render('identity/register.html.twig', ['error' => $error]));
    }

    #[Route('/logout', name: 'identity_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new LogicException('Intercepted by the firewall.');
    }
}
