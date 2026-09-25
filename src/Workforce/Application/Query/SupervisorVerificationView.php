<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

/**
 * What a colleague sees before vouching. For somebody outside the team only
 * the state is filled in: no name, no email, no team.
 */
final readonly class SupervisorVerificationView
{
    public const string PENDING = 'pending';
    public const string VERIFIED = 'verified';
    public const string INACTIVE = 'inactive';
    public const string FORBIDDEN = 'forbidden';
    public const string CANDIDATE = 'candidate';

    public function __construct(
        public string $state,
        public string $candidateName = '',
        public string $maskedEmail = '',
        public string $workplaceName = '',
        public string $teamLabel = '',
        public int $confirmations = 0,
        public int $requiredConfirmations = 0,
        public bool $quorumReachable = true,
        public ?string $myDecision = null,
    ) {
    }
}
