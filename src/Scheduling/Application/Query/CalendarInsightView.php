<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class CalendarInsightView
{
    public function __construct(public string $headline, public string $detail)
    {
    }
}
