<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Why somebody cannot work a given shift.
 *
 * The split matters more than the list: a **temporal** obstacle is one a rota
 * change can remove, so the request is still worth showing — greyed out, with
 * the reason — because seeing which days colleagues are trying to give away is
 * useful even on the days you are busy. Everything else is structural: showing
 * somebody a shift they will never be allowed to do is noise, and notifying
 * them about it is worse.
 */
enum ShiftObstacle: string
{
    case NOT_IN_GROUP = 'not_in_group';
    case ALREADY_STARTED = 'already_started';
    case SHIFT_OVERLAP = 'shift_overlap';
    case INSUFFICIENT_REST = 'insufficient_rest';

    public function isTemporal(): bool
    {
        return match ($this) {
            self::SHIFT_OVERLAP, self::INSUFFICIENT_REST => true,
            self::NOT_IN_GROUP, self::ALREADY_STARTED => false,
        };
    }
}
