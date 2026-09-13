<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

interface CalendarBlockIdGenerator
{
    public function next(): string;
}
