<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class GetShiftPresets
{
    public function __construct(public string $workerId, public bool $includeInactive = false, public ?string $assignmentId = null)
    {
    }
}
