<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Supervision\SupervisorVerificationDecision;

final readonly class VerifySupervisor
{
    public function __construct(public string $workerId, public string $token, public SupervisorVerificationDecision $decision)
    {
    }
}
