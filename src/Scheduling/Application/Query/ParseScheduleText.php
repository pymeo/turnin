<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\RosterSource;

final readonly class ParseScheduleText
{
    public function __construct(
        public string $workerId,
        public string $text,
        public ?string $month = null,
        public RosterSource $source = RosterSource::TEXT,
        public ConflictPolicy $policy = ConflictPolicy::SKIP_EXISTING,
    ) {
    }
}
