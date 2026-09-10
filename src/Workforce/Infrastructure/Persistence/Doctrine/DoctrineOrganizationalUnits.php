<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\OrganizationalUnit;
use App\Workforce\Domain\OrganizationalUnitKind;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\OrganizationalUnitStatus;
use App\Workforce\Domain\WorkplaceId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineOrganizationalUnits implements OrganizationalUnits
{
    public function __construct(private Connection $connection)
    {
    }

    public function byId(string $id): ?OrganizationalUnit
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workforce_organizational_units WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->map($row);
    }

    public function search(WorkplaceId $workplaceId, string $term, int $limit): array
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($term))) ?: mb_strtolower(trim($term));
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM workforce_organizational_units WHERE workplace_id = :workplace AND status <> 'inactive' AND translate(lower(name || ' ' || aliases::text), 'áéíóúüñ', 'aeiouun') LIKE :term ORDER BY (kind = 'floating_team') DESC, name LIMIT :limit", ['workplace' => (string) $workplaceId, 'term' => '%'.addcslashes($folded, '%_\\').'%', 'limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map(fn (array $row): OrganizationalUnit => $this->map($row), $rows);
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): OrganizationalUnit
    {
        return OrganizationalUnit::create($this->text($row['id'] ?? null), new WorkplaceId($this->text($row['workplace_id'] ?? null)), $this->text($row['name'] ?? null), $this->nullableText($row['official_code'] ?? null), $this->aliases($row['aliases'] ?? null), OrganizationalUnitKind::from($this->text($row['kind'] ?? null)), OrganizationalUnitStatus::from($this->text($row['status'] ?? null)), $this->nullableText($row['parent_id'] ?? null), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function nullableText(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
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
