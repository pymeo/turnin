<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Http;

use App\Platform\System\Application\Query\CheckSystemHealth;
use App\Platform\System\Domain\HealthReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unauthenticated liveness/readiness endpoint used by Docker, the load balancer
 * and uptime monitoring.
 *
 * The response is deliberately thin: component names and their status, nothing
 * about hosts, versions or error messages.
 */
final class HealthController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/health', name: 'platform_system_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $report = $this->currentHealth();

        $components = [];
        foreach ($report->components as $component) {
            $components[$component->name->value] = array_filter([
                'status' => $component->status()->value,
                'detail' => $component->detail,
            ], static fn (?string $value): bool => null !== $value);
        }

        $response = new JsonResponse([
            'status' => $report->status()->value,
            'observedAt' => $report->observedAt->format(\DATE_RFC3339),
            'components' => $components,
        ], $report->isServiceable() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);

        // A health answer describes this instant and this instance only.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The declared return type is the guarantee: if the query bus ever answers
     * with something else, PHP fails here rather than three frames later. An
     * assert() would not — production runs with zend.assertions=-1.
     */
    private function currentHealth(): HealthReport
    {
        return $this->handle(new CheckSystemHealth());
    }
}
