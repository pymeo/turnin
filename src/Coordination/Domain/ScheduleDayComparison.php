<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

final readonly class ScheduleDayComparison
{
    /**
     * @param list<BusyInterval> $mine
     * @param list<BusyInterval> $theirs
     */
    public function __construct(public string $date, public ComparisonStatus $status, public array $mine, public array $theirs, public ?BusyInterval $actionableShift = null)
    {
    }
}
