<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

/**
 * @param list<string|null> $slots One entry per day of the rotation: a preset
 *                                 id for a shift, null for a rest day
 */
final readonly class CreateRosterPattern
{
    /** @param list<string|null> $slots */
    public function __construct(public string $workerId, public ?string $name, public array $slots, public ?string $assignmentId = null)
    {
    }
}
