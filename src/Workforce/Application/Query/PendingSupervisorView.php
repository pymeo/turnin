<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class PendingSupervisorView
{
    public function __construct(
        public string $candidateName,
        public string $teamLabel,
        public string $verificationPath,
        public int $confirmations,
        public int $requiredConfirmations,
        public bool $answeredByViewer,
    ) {
    }
}
