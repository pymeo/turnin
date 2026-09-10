<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Http;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoogleOAuthController
{
    public function __construct(private ClientRegistry $clients)
    {
    }

    #[Route('/auth/google', name: 'identity_google_start', methods: ['GET'])]
    public function start(): RedirectResponse
    {
        $client = $this->clients->getClient('google');
        if (!$client instanceof GoogleClient) {
            throw new LogicException('Google OAuth client is unavailable.');
        }

        return $client->redirect(['openid', 'email', 'profile']);
    }

    #[Route('/auth/google/callback', name: 'identity_google_callback', methods: ['GET'])]
    public function callback(): never
    {
        throw new LogicException('Intercepted by the Google authenticator.');
    }
}
