<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

interface Workplaces
{
    public function ofSourceAndExternalId(WorkplaceSource $source, string $externalId): ?Workplace;

    public function save(Workplace $workplace): void;

    /**
     * Persists pending changes, then deactivates active rows absent from a fully parsed source.
     *
     * @param list<string> $presentExternalIds
     */
    public function deactivateMissingFrom(WorkplaceSource $source, array $presentExternalIds, DateTimeImmutable $now): int;

    /** @return list<Workplace> */
    public function searchActive(string $term, int $limit): array;

    public function countActive(): int;
}
