<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\Identity\Infrastructure;

use App\Platform\Identity\Domain\PostAuthenticationDestinationResolver;
use App\Platform\Identity\Infrastructure\Security\PostAuthenticationSuccessHandler;
use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class PostAuthenticationSuccessHandlerTest extends TestCase
{
    public function test_google_and_password_success_preserve_an_internal_deep_link(): void
    {
        $request = Request::create('https://turnin.es/auth/google/callback');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security.main.target_path', '/approval/abc12345');
        $request->setSession($session);
        $user = new SecurityUser('019b2000-0000-7000-8000-000000000001', 'person@example.test', null, true, false);
        $token = new UsernamePasswordToken($user, 'main', []);

        $response = (new PostAuthenticationSuccessHandler(new PostAuthenticationDestinationResolver()))->redirectFor($request, $token, 'main');

        self::assertSame('/approval/abc12345', $response->getTargetUrl());
        self::assertFalse($session->has('_security.main.target_path'));
    }

    public function test_an_external_target_is_discarded(): void
    {
        $request = Request::create('https://turnin.es/auth/google/callback');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security.main.target_path', 'https://evil.example/steal');
        $request->setSession($session);
        $user = new SecurityUser('019b2000-0000-7000-8000-000000000002', 'person@example.test', null, false, true);
        $token = new UsernamePasswordToken($user, 'main', []);

        $response = (new PostAuthenticationSuccessHandler(new PostAuthenticationDestinationResolver()))->redirectFor($request, $token, 'main');

        self::assertSame('/supervisor', $response->getTargetUrl());
    }
}
