<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use DateTimeImmutable;

/** The only moment the plain invitation token exists outside the link itself. */
final readonly class SupervisorInvitationCreated
{
    public function __construct(
        public string $token,
        public string $path,
        public DateTimeImmutable $expiresAt,
        public string $workplaceName,
        public string $teamLabel,
        public int $verifiedSupervisors,
    ) {
    }
}
