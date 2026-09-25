<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class SupervisorInvitationView
{
    public const string OPEN = 'open';
    public const string EXPIRED = 'expired';
    public const string ACCEPTED = 'accepted';
    public const string UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $state,
        public string $workplaceName,
        public string $teamLabel,
        public bool $createdByViewer,
        public bool $viewerAlreadySupervises,
        public bool $acceptedByViewer,
    ) {
    }
}
