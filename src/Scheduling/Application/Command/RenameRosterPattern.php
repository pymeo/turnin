<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class RenameRosterPattern
{
    public function __construct(public string $workerId, public string $patternId, public string $name, public ?string $assignmentId = null)
    {
    }
}
