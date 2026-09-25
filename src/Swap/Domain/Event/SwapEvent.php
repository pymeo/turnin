<?php

declare(strict_types=1);

namespace App\Swap\Domain\Event;

interface SwapEvent
{
    public function eventId(): string;
}
