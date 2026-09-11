<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\Swap\Domain\WorkerDisplayNames;

final readonly class FixedWorkerDisplayNames implements WorkerDisplayNames
{
    /** @param array<string, string> $names */
    public function __construct(private array $names = [])
    {
    }

    public function forWorkers(array $workerIds): array
    {
        return array_intersect_key($this->names, array_flip($workerIds));
    }
}
