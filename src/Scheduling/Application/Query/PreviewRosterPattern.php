<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Domain\ConflictPolicy;

final readonly class PreviewRosterPattern
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
