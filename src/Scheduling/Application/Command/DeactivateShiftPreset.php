<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class DeactivateShiftPreset
{
    public function __construct(public string $workerId, public string $presetId, public ?string $assignmentId = null)
    {
    }
}
