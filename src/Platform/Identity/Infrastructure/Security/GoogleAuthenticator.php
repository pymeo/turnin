<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use App\Platform\Identity\Application\Command\AuthenticateWithExternalIdentity;
use App\Platform\Identity\Domain\ExternalAuthenticationRejected;
use App\Platform\Identity\Domain\ExternalIdentityProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class GoogleAuthenticator extends OAuth2Authenticator
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus, private ClientRegistry $clients, private PostAuthenticationSuccessHandler $successHandler, private LoggerInterface $logger)
    {
        $this->messageBus = $commandBus;
    }

    public function supports(Request $request): bool
    {
        return 'identity_google_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clients->getClient('google');
        if (!$client instanceof GoogleClient) {
            throw new AuthenticationException('Google OAuth client is unavailable.');
        }

        $accessToken = $this->fetchAccessToken($client);
        $googleUser = $client->fetchUserFromToken($accessToken);
        if (!$googleUser instanceof GoogleUser) {
            throw new CustomUserMessageAuthenticationException('Google no ha devuelto un perfil válido.');
        }

        $subject = $googleUser->getId();
        if (!\is_scalar($subject)) {
            throw new CustomUserMessageAuthenticationException('Google no ha devuelto una identidad válida.');
        }

        try {
            $user = $this->handle(new AuthenticateWithExternalIdentity(ExternalIdentityProvider::GOOGLE, (string) $subject, (string) $googleUser->getEmail(), true === $googleUser->getEmailVerified()));
        } catch (ExternalAuthenticationRejected $exception) {
            throw new CustomUserMessageAuthenticationException('No hemos podido vincular esta cuenta de Google.', [], 0, $exception);
        }
        $securityUser = SecurityUser::fromDomain($user);

        return new SelfValidatingPassport(new UserBadge($securityUser->getUserIdentifier(), static fn (): SecurityUser => $securityUser));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return $this->successHandler->redirectFor($request, $token, $firewallName);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->logger->warning('Google authentication failed.', ['exception' => $exception::class]);
        if ($request->hasSession()) {
            $request->getSession()->set('identity.authentication_error', 'No hemos podido iniciar sesión con Google. Inténtalo de nuevo.');
        }

        return new RedirectResponse('/login');
    }
}
