<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class WorkplaceImportReport
{
    /** @var list<WorkplaceSourceImportReport> */
    public array $sources;

    /**
     * @param list<WorkplaceSourceImportReport> $sources
     */
    public function __construct(array $sources, public int $totalActive)
    {
        $this->sources = $sources;
    }

    public function succeeded(): bool
    {
        foreach ($this->sources as $source) {
            if (!$source->succeeded()) {
                return false;
            }
        }

        return true;
    }
}
