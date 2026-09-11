<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

final readonly class ExternalCalendarEventDraft
{
    /** @param array<string, string> $privateMetadata */
    public function __construct(public string $title, public string $description, public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt, public array $privateMetadata = [])
    {
    }
}
