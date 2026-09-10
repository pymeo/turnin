<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface Specialties
{
    /** @return list<Specialty> */
    public function search(string $term, ?string $categoryCode, int $limit): array;
}
