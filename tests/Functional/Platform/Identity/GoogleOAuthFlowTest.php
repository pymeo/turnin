<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform\Identity;

use App\Platform\Identity\Infrastructure\Http\GoogleOAuthController;
use App\Platform\Identity\Infrastructure\Security\GoogleAuthenticator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(GoogleOAuthController::class)]
#[CoversClass(GoogleAuthenticator::class)]
final class GoogleOAuthFlowTest extends WebTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function publicDomains(): iterable
    {
        yield 'development' => ['https://dev.turnin.es/auth/google', 'https://dev.turnin.es/auth/google/callback'];
        yield 'production' => ['https://turnin.es/auth/google', 'https://turnin.es/auth/google/callback'];
    }

    #[DataProvider('publicDomains')]
    public function test_google_start_generates_exact_callback_for_current_https_domain(string $startUrl, string $expectedCallback): void
    {
        $client = static::createClient();
        $client->request('GET', $startUrl);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $parameters);
        self::assertSame($expectedCallback, $parameters['redirect_uri'] ?? null);
        self::assertSame('openid email profile', $parameters['scope'] ?? null);
        self::assertNotSame('', $parameters['state'] ?? '');
    }

    public function test_invalid_state_is_rejected_without_contacting_google(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://dev.turnin.es/auth/google/callback?code=never-used&state=invalid');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'No hemos podido iniciar sesión con Google');
    }

    public function test_login_and_registration_offer_one_shared_google_entry_point(): void
    {
        $client = static::createClient();
        $login = $client->request('GET', '/login');
        self::assertCount(1, $login->filter('a[href="/auth/google"]'));
        self::assertSelectorTextContains('a[href="/auth/google"]', 'Continuar con Google');

        $registration = $client->request('GET', '/register');
        self::assertCount(1, $registration->filter('a[href="/auth/google"]'));
    }
}
