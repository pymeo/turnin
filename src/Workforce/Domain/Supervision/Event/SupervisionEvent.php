<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision\Event;

interface SupervisionEvent
{
    /** Stable per fact, so a repeated delivery never notifies twice. */
    public function eventId(): string;
}
