<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class RestBlockOpportunityView
{
    /** @param list<string> $reasons
     * @param list<string> $recommendedPeople
     */
    public function __construct(public string $assignmentId, public string $swapPoolId, public string $date, public string $dateHeadline, public string $hours, public string $duration, public string $label, public int $resultingDays, public string $restStartsAt, public string $restEndsAt, public int $score, public array $reasons, public array $recommendedPeople, public bool $alreadyOpen)
    {
    }
}
