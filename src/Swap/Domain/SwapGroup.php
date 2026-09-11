<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * A swap pool the signed-in worker actually belongs to, with the labels the
 * interface needs to name it.
 *
 * Workforce owns the pool and the membership; this is the projection Swap asked
 * for. The identity is `poolId` — never the names, which are presentation and
 * repeat across centres.
 */
final readonly class SwapGroup
{
    public function __construct(
        public string $poolId,
        public string $assignmentId,
        public string $workplaceName,
        public string $destinationName,
        public string $categoryName,
        public bool $primary,
        public string $timeZoneId = 'Europe/Madrid',
        public string $functionalArea = '',
    ) {
    }

    /**
     * The destination names the group when there is one; otherwise the
     * functional area does, because a pool told apart only by that would
     * otherwise appear with no name at all.
     */
    public function label(): string
    {
        $where = '' !== $this->destinationName ? $this->destinationName : $this->functionalArea;
        $parts = array_values(array_filter([$where, $this->categoryName]));

        return [] === $parts ? $this->workplaceName : implode(' · ', $parts);
    }

    public function fullLabel(): string
    {
        return $this->workplaceName.' · '.$this->label();
    }
}
