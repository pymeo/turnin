<?php

declare(strict_types=1);

namespace App\Swap\Domain;

final readonly class ShiftExchangePolicy
{
    public function __construct(
        public string $swapPoolId,
        public bool $requiresApproval,
        public bool $allowsCoverage,
    ) {
    }
}
