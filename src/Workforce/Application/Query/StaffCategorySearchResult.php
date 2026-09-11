<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class StaffCategorySearchResult
{
    /** @param list<string> $aliases */
    public function __construct(public string $id, public string $name, public string $description, public array $aliases, public bool $specialtyRequired, public bool $functionalAreaRequired, public bool $featured)
    {
    }
}
