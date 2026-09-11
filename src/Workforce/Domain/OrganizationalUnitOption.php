<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

final readonly class OrganizationalUnitOption
{
    /** @param list<string> $aliases */
    public function __construct(public string $selectionId, public string $name, public array $aliases, public OrganizationalUnitKind $kind, public OrganizationalUnitOrigin $origin, public DestinationGroup $group, public bool $featured)
    {
    }
}
