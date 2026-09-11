<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\DestinationGroup;
use App\Workforce\Domain\OrganizationalUnit;
use App\Workforce\Domain\OrganizationalUnitIdGenerator;
use App\Workforce\Domain\OrganizationalUnitKind;
use App\Workforce\Domain\OrganizationalUnitOption;
use App\Workforce\Domain\OrganizationalUnitOrigin;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\OrganizationalUnitStatus;
use App\Workforce\Domain\WorkplaceId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class DoctrineOrganizationalUnits implements OrganizationalUnits
{
    public function __construct(private Connection $connection, private OrganizationalUnitIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function byId(string $id): ?OrganizationalUnit
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workforce_organizational_units WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->map($row);
    }

    public function discover(WorkplaceId $workplaceId, string $term, int $limit): array
    {
        $term = $this->normalize($term);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $parameters = ['workplace' => (string) $workplaceId, 'empty' => '' === $term, 'term' => $like, 'limit' => $limit];
        $types = ['empty' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER];
        $localRows = $this->connection->fetchAllAssociative("SELECT u.*, COUNT(m.id) FILTER (WHERE m.active = TRUE) AS use_count FROM workforce_organizational_units u LEFT JOIN workforce_swap_pools p ON p.organizational_unit_id = u.id LEFT JOIN workforce_swap_pool_memberships m ON m.swap_pool_id = p.id WHERE u.workplace_id = :workplace AND u.status <> 'inactive' AND (:empty OR u.normalized_name LIKE :term OR translate(lower(u.aliases::text), 'áéíóúüñ', 'aeiouun') LIKE :term) GROUP BY u.id ORDER BY use_count DESC, u.name LIMIT :limit", $parameters, $types);
        $remaining = max(0, $limit - \count($localRows));
        $definitionRows = 0 === $remaining ? [] : $this->connection->fetchAllAssociative("SELECT d.* FROM workforce_unit_definitions d WHERE d.active = TRUE AND (:empty OR d.normalized_name LIKE :term OR translate(lower(d.aliases::text), 'áéíóúüñ', 'aeiouun') LIKE :term) AND NOT EXISTS (SELECT 1 FROM workforce_organizational_units u WHERE u.workplace_id = :workplace AND u.normalized_name = d.normalized_name AND u.status <> 'inactive') ORDER BY d.featured DESC, d.display_order, d.name LIMIT :limit", ['workplace' => (string) $workplaceId, 'empty' => '' === $term, 'term' => $like, 'limit' => $remaining], $types);

        $options = array_map(fn (array $row): OrganizationalUnitOption => new OrganizationalUnitOption('unit:'.$this->text($row['id'] ?? null), $this->text($row['name'] ?? null), $this->aliases($row['aliases'] ?? null), OrganizationalUnitKind::from($this->text($row['kind'] ?? null)), OrganizationalUnitOrigin::from($this->text($row['origin'] ?? null)), DestinationGroup::from($this->text($row['group_name'] ?? null)), $this->positive($row['use_count'] ?? null)), $localRows);
        foreach ($definitionRows as $row) {
            $options[] = new OrganizationalUnitOption('reference:'.$this->text($row['code'] ?? null), $this->text($row['name'] ?? null), $this->aliases($row['aliases'] ?? null), OrganizationalUnitKind::from($this->text($row['kind'] ?? null)), OrganizationalUnitOrigin::TURNIN_REFERENCE, DestinationGroup::from($this->text($row['group_name'] ?? null)), (bool) ($row['featured'] ?? false));
        }

        return $options;
    }

    public function resolveSelection(WorkplaceId $workplaceId, string $selectionId): OrganizationalUnit
    {
        if (str_starts_with($selectionId, 'unit:')) {
            $selectionId = substr($selectionId, 5);
        }
        if (!str_starts_with($selectionId, 'reference:')) {
            $unit = $this->byId($selectionId);
            if (null === $unit || !$unit->workplaceId()->equals($workplaceId) || OrganizationalUnitStatus::INACTIVE === $unit->status()) {
                throw new InvalidArgumentException('El destino no pertenece al centro seleccionado.');
            }

            return $unit;
        }

        $definition = $this->connection->fetchAssociative('SELECT * FROM workforce_unit_definitions WHERE code = :code AND active = TRUE', ['code' => substr($selectionId, 10)]);
        if (false === $definition) {
            throw new InvalidArgumentException('Selecciona un destino válido.');
        }
        $existing = $this->connection->fetchAssociative("SELECT * FROM workforce_organizational_units WHERE workplace_id = :workplace AND normalized_name = :normalized AND status <> 'inactive'", ['workplace' => (string) $workplaceId, 'normalized' => $definition['normalized_name']]);
        if (false !== $existing) {
            return $this->map($existing);
        }

        return $this->insert($workplaceId, $this->text($definition['name'] ?? null), $this->aliases($definition['aliases'] ?? null), OrganizationalUnitKind::from($this->text($definition['kind'] ?? null)), OrganizationalUnitStatus::VERIFIED, OrganizationalUnitOrigin::TURNIN_REFERENCE, DestinationGroup::from($this->text($definition['group_name'] ?? null)), $this->text($definition['normalized_name'] ?? null));
    }

    public function addLocal(WorkplaceId $workplaceId, string $name): OrganizationalUnit
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('La denominación de la unidad debe tener entre 2 y 120 caracteres.');
        }
        $normalized = $this->normalize($name);
        $existing = $this->connection->fetchAssociative("SELECT * FROM workforce_organizational_units WHERE workplace_id = :workplace AND normalized_name = :normalized AND status <> 'inactive'", ['workplace' => (string) $workplaceId, 'normalized' => $normalized]);
        if (false !== $existing) {
            return $this->map($existing);
        }

        return $this->insert($workplaceId, $name, [], OrganizationalUnitKind::FIXED_SERVICE, OrganizationalUnitStatus::PENDING, OrganizationalUnitOrigin::LOCAL, DestinationGroup::HABITUAL, $normalized);
    }

    /** @param list<string> $aliases */
    private function insert(WorkplaceId $workplaceId, string $name, array $aliases, OrganizationalUnitKind $kind, OrganizationalUnitStatus $status, OrganizationalUnitOrigin $origin, DestinationGroup $group, string $normalized): OrganizationalUnit
    {
        $id = $this->ids->next();
        $now = $this->clock->now();
        $this->connection->insert('workforce_organizational_units', ['id' => $id, 'workplace_id' => (string) $workplaceId, 'official_code' => null, 'name' => $name, 'normalized_name' => $normalized, 'aliases' => json_encode($aliases, \JSON_THROW_ON_ERROR), 'kind' => $kind->value, 'status' => $status->value, 'origin' => $origin->value, 'group_name' => $group->value, 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now], ['created_at' => 'datetime_immutable', 'updated_at' => 'datetime_immutable']);

        return OrganizationalUnit::create($id, $workplaceId, $name, null, $aliases, $kind, $status, null, $now, $origin, $group);
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): OrganizationalUnit
    {
        return OrganizationalUnit::create($this->text($row['id'] ?? null), new WorkplaceId($this->text($row['workplace_id'] ?? null)), $this->text($row['name'] ?? null), $this->nullableText($row['official_code'] ?? null), $this->aliases($row['aliases'] ?? null), OrganizationalUnitKind::from($this->text($row['kind'] ?? null)), OrganizationalUnitStatus::from($this->text($row['status'] ?? null)), $this->nullableText($row['parent_id'] ?? null), new DateTimeImmutable($this->text($row['created_at'] ?? null)), OrganizationalUnitOrigin::from($this->text($row['origin'] ?? 'local')), DestinationGroup::from($this->text($row['group_name'] ?? 'habitual')));
    }

    private function normalize(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value))) ?: mb_strtolower(trim($value));

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function positive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
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
