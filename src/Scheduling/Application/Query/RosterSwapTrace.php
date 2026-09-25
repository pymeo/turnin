<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class RosterSwapTrace
{
    /** @param list<RosterSwapTraceSegment> $segments */
    public function __construct(public string $date, public RosterSwapRole $role, public string $colleagueDisplayName, public string $agreementId, public RosterAgreementStatus $status, public array $segments)
    {
    }
}
