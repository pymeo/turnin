<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface StaffCategories
{
    /** @return list<StaffCategory> */
    public function search(string $term, int $limit): array;

    public function byId(string $id): ?StaffCategory;
}
