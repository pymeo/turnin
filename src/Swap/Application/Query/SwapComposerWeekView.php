<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class SwapComposerWeekView
{
    /** @param list<SwapComposerDayView> $days seven, Monday first */
    public function __construct(public string $label, public array $days)
    {
    }
}
