<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision\Event;

/**
 * The team is asked to vouch for a candidate, who either accepted a
 * colleague's invitation (`inviterId`) or asked themselves (`inviterId` null).
 */
final readonly class SupervisorVerificationRequested implements SupervisionEvent
{
    /** @param list<string> $verifierIds colleagues who have not answered yet */
    public function __construct(
        public string $assignmentId,
        public string $candidateId,
        public string $candidateName,
        public ?string $inviterId,
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
