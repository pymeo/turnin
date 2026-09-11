<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface OrganizationalUnits
{
    /** @return list<OrganizationalUnitOption> */
    public function discover(WorkplaceId $workplaceId, string $term, int $limit): array;

    public function byId(string $id): ?OrganizationalUnit;

    public function resolveSelection(WorkplaceId $workplaceId, string $selectionId): OrganizationalUnit;

    public function addLocal(WorkplaceId $workplaceId, string $name): OrganizationalUnit;
}
