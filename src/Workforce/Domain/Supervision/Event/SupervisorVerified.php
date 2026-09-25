<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision\Event;

final readonly class SupervisorVerified implements SupervisionEvent
{
    /** @param list<string> $memberIds the team, without the supervisor */
    public function __construct(
        public string $assignmentId,
        public string $supervisorId,
        public string $supervisorName,
        public string $swapPoolId,
        public string $teamLabel,
        public array $memberIds,
    ) {
    }

    public function eventId(): string
    {
        return 'supervisor:'.$this->assignmentId.':verified';
    }
}
