<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Application\Query\OrganizationalUnitSearchResult;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceReader;
use InvalidArgumentException;

final readonly class CreateLocalOrganizationalUnitHandler
{
    public function __construct(private WorkplaceReader $workplaces, private OrganizationalUnits $units)
    {
    }

    public function __invoke(CreateLocalOrganizationalUnit $command): OrganizationalUnitSearchResult
    {
        $workplaceId = new WorkplaceId($command->workplaceId);
        $workplace = $this->workplaces->byId($workplaceId);
        if (null === $workplace || !$workplace->active()) {
            throw new InvalidArgumentException('Selecciona primero un centro válido.');
        }
        $unit = $this->units->addLocal($workplaceId, $command->name);

        return new OrganizationalUnitSearchResult('unit:'.$unit->id(), $unit->name(), $unit->aliases(), $unit->kind()->value, $unit->origin()->value, $unit->group()->value, false);
    }
}
