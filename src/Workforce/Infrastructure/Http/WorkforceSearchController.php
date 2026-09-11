<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Http;

use App\Workforce\Application\Command\CreateLocalOrganizationalUnit;
use App\Workforce\Application\Query\OrganizationalUnitSearchResult;
use App\Workforce\Application\Query\SearchOrganizationalUnits;
use App\Workforce\Application\Query\SearchStaffCategories;
use App\Workforce\Application\Query\SearchWorkplaces;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class WorkforceSearchController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus, private MessageBusInterface $commandBus, private CsrfTokenManagerInterface $csrf)
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
        $results = $this->handle(new SearchStaffCategories($term, 20));

        return new JsonResponse(['results' => array_map(static fn ($result): array => ['id' => $result->id, 'name' => $result->name, 'description' => $result->description, 'aliases' => $result->aliases, 'specialtyRequired' => $result->specialtyRequired, 'functionalAreaRequired' => $result->functionalAreaRequired, 'featured' => $result->featured], $results)]);
    }

    #[Route('/api/workplaces/{workplaceId}/units', name: 'workforce_search_units', methods: ['GET'])]
    public function units(string $workplaceId, Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        $results = $this->handle(new SearchOrganizationalUnits($workplaceId, $term, 20));

        return new JsonResponse(['results' => array_map(static fn ($result): array => ['id' => $result->id, 'name' => $result->name, 'aliases' => $result->aliases, 'kind' => $result->kind, 'origin' => $result->origin, 'group' => $result->group, 'featured' => $result->featured], $results)]);
    }

    #[Route('/api/workplaces/{workplaceId}/units/local', name: 'workforce_create_local_unit', methods: ['POST'])]
    public function createLocalUnit(string $workplaceId, Request $request): JsonResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('onboarding', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return new JsonResponse(['error' => 'La sesión ha caducado.'], 419);
        }
        try {
            $envelope = $this->commandBus->dispatch(new CreateLocalOrganizationalUnit($workplaceId, $request->request->getString('name')));
            $result = $envelope->last(HandledStamp::class)?->getResult();
            if (!$result instanceof OrganizationalUnitSearchResult) {
                throw new RuntimeException('The local unit command did not return a unit.');
            }

            return new JsonResponse(['result' => ['id' => $result->id, 'name' => $result->name, 'aliases' => $result->aliases, 'kind' => $result->kind, 'origin' => $result->origin, 'group' => $result->group, 'featured' => false]], 201);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }
    }
}
