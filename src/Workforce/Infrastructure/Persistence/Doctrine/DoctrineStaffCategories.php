<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\StaffCategory;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineStaffCategories implements StaffCategories
{
    public function __construct(private Connection $connection)
    {
    }

    public function byId(string $id): ?StaffCategory
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workforce_staff_categories WHERE id = :id AND active = TRUE', ['id' => $id]);

        return false === $row ? null : $this->map($row);
    }

    public function search(string $term, int $limit): array
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($term))) ?: mb_strtolower(trim($term));
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM workforce_staff_categories WHERE active = TRUE AND translate(lower(name || ' ' || description || ' ' || aliases::text), 'áéíóúüñ', 'aeiouun') LIKE :term ORDER BY name LIMIT :limit", ['term' => '%'.addcslashes($folded, '%_\\').'%', 'limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map(fn (array $row): StaffCategory => $this->map($row), $rows);
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): StaffCategory
    {
        $aliases = $this->aliases($row['aliases'] ?? null);

        return StaffCategory::reference($this->text($row['id'] ?? null), $this->text($row['code'] ?? null), $this->text($row['name'] ?? null), $this->text($row['description'] ?? null), $aliases, (bool) ($row['specialty_required'] ?? false), (bool) ($row['functional_area_required'] ?? false), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private function aliases(mixed $value): array
    {
        if (\is_string($value)) {
            $value = json_decode($value, true);
        }

        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
