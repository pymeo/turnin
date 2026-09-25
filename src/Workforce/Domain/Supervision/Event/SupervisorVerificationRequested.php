<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision\Event;

/** A candidate accepted an invitation and the team is asked to vouch. */
final readonly class SupervisorVerificationRequested implements SupervisionEvent
{
    /** @param list<string> $verifierIds colleagues who have not answered yet */
    public function __construct(
        public string $assignmentId,
        public string $candidateId,
        public string $candidateName,
        public string $inviterId,
        public string $teamLabel,
        public string $verificationToken,
        public array $verifierIds,
    ) {
    }

    public function eventId(): string
    {
        return 'supervisor:'.$this->assignmentId.':verification-requested';
    }
}
