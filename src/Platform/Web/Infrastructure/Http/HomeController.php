<?php

declare(strict_types=1);

namespace App\Platform\Web\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The public landing page.
 *
 * It has no domain behind it on purpose: it explains what Turnin does and hands
 * the visitor to onboarding. When onboarding exists it will live in its own
 * bounded context and this page will link to it.
 */
final readonly class HomeController
{
    public function __construct(private Environment $twig)
    {
    }

    #[Route('/', name: 'web_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('web/home.html.twig'));
    }
}
