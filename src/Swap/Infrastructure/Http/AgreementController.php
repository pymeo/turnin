<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Http;

use App\Swap\Application\Query\GetPublicSwapAgreement;
use App\Swap\Application\Query\GetSwapAgreement;
use App\Swap\Application\Query\SwapAgreementView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Twig\Environment;

final readonly class AgreementController
{
    public function __construct(private MessageBusInterface $queryBus, private Environment $twig, private SwapSession $session, private UrlGeneratorInterface $urls)
    {
    }

    #[Route('/app/changes/agreements/{proposalId}', name: 'swap_agreement', methods: ['GET'])]
    public function privateAgreement(string $proposalId): Response
    {
        $workerId = $this->session->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }
        try {
            $agreement = $this->session->handled($this->queryBus, new GetSwapAgreement($workerId, $proposalId));
        } catch (Throwable) {
            return new Response('Ese cambio no está disponible.', Response::HTTP_NOT_FOUND);
        }
        if (!$agreement instanceof SwapAgreementView) {
            return new Response('Ese cambio no está disponible.', Response::HTTP_NOT_FOUND);
        }
        $publicUrl = $this->urls->generate('swap_agreement_public', ['token' => $agreement->publicToken], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->privateResponse($this->twig->render('swap/agreement.html.twig', ['agreement' => $agreement, 'publicUrl' => $publicUrl]));
    }

    #[Route('/cambio/{token}', name: 'swap_agreement_public', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
    public function publicAgreement(string $token): Response
    {
        try {
            $agreement = $this->session->handled($this->queryBus, new GetPublicSwapAgreement($token));
        } catch (Throwable) {
            return new Response('Cambio no encontrado.', Response::HTTP_NOT_FOUND, ['X-Robots-Tag' => 'noindex, nofollow']);
        }
        if (!$agreement instanceof SwapAgreementView) {
            return new Response('Cambio no encontrado.', Response::HTTP_NOT_FOUND, ['X-Robots-Tag' => 'noindex, nofollow']);
        }

        return $this->privateResponse($this->twig->render('swap/agreement_public.html.twig', ['agreement' => $agreement]));
    }

    private function privateResponse(string $html): Response
    {
        return new Response($html, Response::HTTP_OK, [
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
