<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Http;

use App\Workforce\Application\Query\SearchOrganizationalUnits;
use App\Workforce\Application\Query\SearchStaffCategories;
use App\Workforce\Application\Query\SearchWorkplaces;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class WorkforceSearchController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/workplaces', name: 'workforce_search_workplaces', methods: ['GET'])]
    public function workplaces(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        if ('' === $term) {
            return new JsonResponse(['results' => []]);
        }
        $results = $this->handle(new SearchWorkplaces($term, 20));

        return new JsonResponse(['results' => array_map(static fn ($result): array => ['id' => $result->id, 'name' => $result->name, 'municipality' => $result->municipality, 'province' => $result->province, 'type' => $result->type->value], $results)]);
    }

    #[Route('/api/staff-categories', name: 'workforce_search_categories', methods: ['GET'])]
    public function categories(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        if ('' === $term) {
            return new JsonResponse(['results' => []]);
        }
        $results = $this->handle(new SearchStaffCategories($term, 20));

        return new JsonResponse(['results' => array_map(static fn ($result): array => ['id' => $result->id, 'name' => $result->name, 'description' => $result->description, 'aliases' => $result->aliases, 'specialtyRequired' => $result->specialtyRequired, 'functionalAreaRequired' => $result->functionalAreaRequired], $results)]);
    }

    #[Route('/api/workplaces/{workplaceId}/units', name: 'workforce_search_units', methods: ['GET'])]
    public function units(string $workplaceId, Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        $results = $this->handle(new SearchOrganizationalUnits($workplaceId, $term, 20));

        return new JsonResponse(['results' => array_map(static fn ($result): array => ['id' => $result->id, 'name' => $result->name, 'aliases' => $result->aliases, 'kind' => $result->kind, 'status' => $result->status], $results)]);
    }
}
