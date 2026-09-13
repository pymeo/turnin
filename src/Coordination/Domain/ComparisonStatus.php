<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

enum ComparisonStatus: string
{
    case BOTH_FREE = 'both_free';
    case BOTH_WORKING_SIMILAR_HOURS = 'both_working_similar_hours';
    case A_WORKS_B_FREE = 'a_works_b_free';
    case A_FREE_B_WORKS = 'a_free_b_works';
    case DIFFERENT_WORKING_HOURS = 'different_working_hours';
    case BOTH_BUSY_DIFFERENTLY = 'both_busy_differently';
}
