<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class SearchOrganizationalUnits
{
    public function __construct(public string $workplaceId, public string $term, public int $limit = 20)
    {
    }
}
