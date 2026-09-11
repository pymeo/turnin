<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

/**
 * Idempotent: a worker who already has presets keeps exactly the ones they
 * have. Only a genuinely empty catalogue is seeded.
 */
final readonly class EnsureShiftPresets
{
    public function __construct(public string $workerId, public ?string $assignmentId = null)
    {
    }
}
