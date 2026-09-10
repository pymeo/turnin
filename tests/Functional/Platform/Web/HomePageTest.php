<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform\Web;

use App\Platform\Web\Infrastructure\Http\HomeController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(HomeController::class)]
final class HomePageTest extends WebTestCase
{
    public function test_the_landing_page_explains_what_turnin_does(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Turnin');
        self::assertSelectorTextContains('body', 'Tus turnos. Tu tiempo.');
    }

    public function test_it_offers_registration_and_login_actions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('a.btn-primary[href="/register"]'));
        self::assertSelectorTextContains('a.btn-primary', 'Crear cuenta');
        self::assertCount(1, $crawler->filter('a.btn-secondary[href="/login"]'));
    }

    /**
     * The PWA shell is part of the page contract: without these tags the app is
     * not installable, and mobile browsers fall back to desktop layout rules.
     */
    public function test_the_page_is_wired_up_as_an_installable_mobile_app(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('link[rel="manifest"][href="/manifest.webmanifest"]'));
        self::assertStringContainsString(
            'viewport-fit=cover',
            (string) $crawler->filter('meta[name="viewport"]')->attr('content'),
        );
        self::assertGreaterThan(0, $crawler->filter('meta[name="theme-color"]')->count());
        self::assertCount(1, $crawler->filter('link[rel="apple-touch-icon"]'));
    }

    public function test_it_explains_the_current_next_step(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('body', 'Empieza definiendo tu centro');
    }
}
