<?php

declare(strict_types=1);

namespace App\Swap\Domain;

final readonly class OpportunityScoreWeights
{
    public const int RESULTING_REST_DAY = 10;
    public const int GAINED_REST_DAY = 5;
    public const int JOINS_REST_BLOCKS = 20;
    public const int AVAILABLE_CANDIDATE = 10;
    public const int PENDING_EXCHANGE = 40;
    public const int CONTEXTUAL_PARTNER = 30;
    public const int PREFERRED_MONTH = 30;
    public const int PREFERRED_KIND = 20;
    public const int PREFERRED_DURATION = 20;
    public const int PREFERRED_WEEKDAY = 15;
    /** A shift of roughly the same length is the easiest trade to say yes to. */
    public const int CLOSE_DURATION = 20;
    /** Between two equally good options, the sooner one is worth more. */
    public const int NEARBY_DATE = 10;
}
