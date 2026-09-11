<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Application;

use App\Workforce\Application\Query\SearchStaffCategories;
use App\Workforce\Application\Query\SearchStaffCategoriesHandler;
use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\StaffCategory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SearchStaffCategoriesHandlerTest extends TestCase
{
    public function test_it_limits_and_exposes_aliases_for_progressive_onboarding(): void
    {
        $category = StaffCategory::reference('0198f8c0-0000-7000-8000-000000000001', 'nursing_assistant', 'TCAE', 'Cuidados Auxiliares', ['auxiliar de clínica'], false, false, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $handler = new SearchStaffCategoriesHandler(new class($category) implements StaffCategories {
            public function __construct(private StaffCategory $category)
            {
            }

            public function search(string $term, int $limit): array
            {
                return [$this->category];
            }

            public function byId(string $id): ?StaffCategory
            {
                return 'missing' === $id ? null : $this->category;
            }
        });

        $results = $handler(new SearchStaffCategories('auxiliar', 100));

        self::assertCount(1, $results);
        self::assertSame(['auxiliar de clínica'], $results[0]->aliases);
        self::assertSame('TCAE', $results[0]->name);
        self::assertTrue($results[0]->featured);
    }
}
