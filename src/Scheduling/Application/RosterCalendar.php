<?php

declare(strict_types=1);

namespace App\Scheduling\Application;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\WorkDate;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * "What day is it where this worker works?".
 *
 * Turnin is national and Canarias is an hour behind the peninsula, so at 00:30
 * peninsular time it is still yesterday in Las Palmas. Highlighting the wrong
 * cell as "hoy" on a calendar is a small bug with an outsized effect on trust.
 */
final readonly class RosterCalendar
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function today(AssignedWorker $worker): WorkDate
    {
        return $this->dateFor($this->clock->now(), $worker);
    }

    public function nowLocal(AssignedWorker $worker): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($worker->timeZone());
    }

    public function dateFor(DateTimeImmutable $instant, AssignedWorker $worker): WorkDate
    {
        $local = $instant->setTimezone($worker->timeZone());

        return WorkDate::of((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }
}
