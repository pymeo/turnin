<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeZone;

/**
 * Everything Scheduling needs to know about who owns a roster. Resolved from
 * the session on the server — never from an identifier the browser sent, which
 * is the difference between "my calendar" and "anyone's calendar".
 */
final readonly class AssignedWorker
{
    public function __construct(public string $workerId, public string $assignmentId, public string $timeZoneId, public string $workplaceName, public bool $primary = true, public string $destinationName = '')
    {
    }

    /**
     * Turnin is national: Canarias is an hour behind the peninsula, so "is my
     * next shift today?" has two different answers depending on the centre.
     */
    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timeZoneId);
    }

    public function shortLabel(): string
    {
        return '' === $this->destinationName ? $this->workplaceName : $this->workplaceName.' · '.$this->destinationName;
    }
}
