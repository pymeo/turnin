<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\WorkplaceType;

final readonly class WorkplaceSearchResult
{
    public function __construct(
        public string $id,
        public string $name,
        public WorkplaceType $type,
        public string $municipality,
        public string $province,
        public string $autonomousCommunity,
    ) {
    }
}
