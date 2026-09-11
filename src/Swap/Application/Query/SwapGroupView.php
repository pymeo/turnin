<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class SwapGroupView
{
    public function __construct(
        public string $poolId,
        public string $assignmentId,
        public string $label,
        public string $fullLabel,
        public string $workplaceName,
        public bool $primary,
    ) {
    }
}
