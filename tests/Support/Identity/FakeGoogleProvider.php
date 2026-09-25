<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Google without the network: the real bundle client, state check and
 * authenticator run; only the two calls to Google's servers are answered here.
 */
final class FakeGoogleProvider extends Google
{
    /** @param array<string, mixed> $profile */
    public function __construct(private array $profile)
    {
        parent::__construct(['clientId' => 'test', 'clientSecret' => 'test', 'redirectUri' => 'https://turnin.test/auth/google/callback']);
    }

    /**
     * The next person to come back from "Google".
     *
     * @param array<string, mixed> $profile
     */
    public function answerAs(array $profile): void
    {
        $this->profile = $profile;
    }

    /** @param array<string, mixed> $options */
    public function getAccessToken($grant, array $options = []): AccessTokenInterface
    {
        return new AccessToken(['access_token' => 'fake-google-token', 'expires_in' => 3600]);
    }

    public function getResourceOwner(AccessToken $token): ResourceOwnerInterface
    {
        return new GoogleUser($this->profile);
    }
}
