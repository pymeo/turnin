<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class SearchStaffCategories
{
    public function __construct(public string $term, public int $limit = 20)
    {
    }
}
