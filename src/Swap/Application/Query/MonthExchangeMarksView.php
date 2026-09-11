<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class MonthExchangeMarksView
{
    /**
     * @param list<string> $publishedDates days of this calendar with an open request
     * @param list<string> $availableDates days the worker offered to work
     */
    public function __construct(public array $publishedDates, public array $availableDates)
    {
    }
}
