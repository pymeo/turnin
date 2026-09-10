<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface OrganizationalUnits
{
    /** @return list<OrganizationalUnit> */
    public function search(WorkplaceId $workplaceId, string $term, int $limit): array;

    public function byId(string $id): ?OrganizationalUnit;
}
