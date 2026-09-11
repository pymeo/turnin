<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class ReorderShiftPresets
{
    /** @param list<string> $presetIdsInOrder */
    public function __construct(public string $workerId, public array $presetIdsInOrder, public ?string $assignmentId = null)
    {
    }
}
