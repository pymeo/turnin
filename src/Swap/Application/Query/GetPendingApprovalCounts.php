<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/** Result: array<string, int>, pool id → agreements waiting, for verified pools only. */
final readonly class GetPendingApprovalCounts
{
    public function __construct(public string $supervisorUserId)
    {
    }
}
