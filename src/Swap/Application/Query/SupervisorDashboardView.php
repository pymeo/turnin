<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class SupervisorDashboardView
{
    /** @param list<SwapAgreementView> $pending */
    public function __construct(public array $pending)
    {
    }
}
