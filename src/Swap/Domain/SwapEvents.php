<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\Swap\Domain\Event\SwapEvent;

/** Events leave Swap through this port; delivery is never part of an agreement. */
interface SwapEvents
{
    public function publishAfterCommit(SwapEvent $event): void;
}
