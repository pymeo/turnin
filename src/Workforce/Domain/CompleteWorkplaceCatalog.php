<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use InvalidArgumentException;

final readonly class CompleteWorkplaceCatalog
{
    /** @var list<ImportedWorkplace> */
    public array $workplaces;

    /**
     * @param list<ImportedWorkplace> $workplaces
     */
    public function __construct(
        public WorkplaceSource $source,
        array $workplaces,
        public int $rejectedRows = 0,
    ) {
        if ([] === $workplaces) {
            throw new InvalidArgumentException('A complete catalog cannot be empty.');
        }

        foreach ($workplaces as $workplace) {
            if ($source !== $workplace->source) {
                throw new InvalidArgumentException('Every workplace must belong to the catalog source.');
            }
        }

        $this->workplaces = $workplaces;
    }
}
