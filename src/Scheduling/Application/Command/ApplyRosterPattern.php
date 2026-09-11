<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Domain\ConflictPolicy;

final readonly class ApplyRosterPattern
{
    public function __construct(
        public string $workerId,
        public string $patternId,
        public string $from,
        public string $to,
        public ConflictPolicy $policy = ConflictPolicy::SKIP_EXISTING,
    ) {
    }
}
