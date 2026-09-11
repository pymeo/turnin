<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\WorkplaceId;

final readonly class SearchOrganizationalUnitsHandler
{
    public function __construct(private OrganizationalUnits $units)
    {
    }

    /** @return list<OrganizationalUnitSearchResult> */
    public function __invoke(SearchOrganizationalUnits $query): array
    {
        $limit = max(1, min(20, $query->limit));

        return array_map(static fn ($unit): OrganizationalUnitSearchResult => new OrganizationalUnitSearchResult($unit->selectionId, $unit->name, $unit->aliases, $unit->kind->value, $unit->origin->value, $unit->group->value, $unit->featured), $this->units->discover(new WorkplaceId($query->workplaceId), $query->term, $limit));
    }
}
