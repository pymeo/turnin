<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\StaffCategories;

final readonly class SearchStaffCategoriesHandler
{
    public function __construct(private StaffCategories $categories)
    {
    }

    /** @return list<StaffCategorySearchResult> */
    public function __invoke(SearchStaffCategories $query): array
    {
        $limit = max(1, min(20, $query->limit));

        return array_map(static fn ($category): StaffCategorySearchResult => new StaffCategorySearchResult($category->id(), $category->name(), $category->description(), $category->aliases(), $category->specialtyRequired(), $category->functionalAreaRequired()), $this->categories->search($query->term, $limit));
    }
}
