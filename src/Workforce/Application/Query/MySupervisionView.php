<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class MySupervisionView
{
    public function __construct(
        public string $assignmentId,
        public string $swapPoolId,
        public string $workplaceName,
        public string $teamLabel,
        public bool $verified,
        public int $confirmations,
        public int $requiredConfirmations,
        public bool $quorumReachable,
        public string $verificationUrl,
        public string $shareMessage,
    ) {
    }

    public function missing(): int
    {
        return max(0, $this->requiredConfirmations - $this->confirmations);
    }

    public function progressPercent(): int
    {
        return 0 === $this->requiredConfirmations ? 0 : (int) min(100, round(100 * $this->confirmations / $this->requiredConfirmations));
    }
}
