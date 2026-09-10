<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\Workplaces;

final readonly class SearchWorkplacesHandler
{
    public function __construct(private Workplaces $workplaces)
    {
    }

    /** @return list<WorkplaceSearchResult> */
    public function __invoke(SearchWorkplaces $query): array
    {
        return array_map(
            static fn (Workplace $workplace): WorkplaceSearchResult => new WorkplaceSearchResult(
                (string) $workplace->id(),
                $workplace->name(),
                $workplace->type(),
                $workplace->municipality(),
                $workplace->province(),
                $workplace->autonomousCommunity(),
            ),
            $this->workplaces->searchActive($query->term, $query->limit),
        );
    }
}
