<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * "Somebody wants to give a shift away and I am interested — show me my own
 * rota so I can decide which of mine to ask for in return.".
 *
 * The week offset and the selected shift travel in the query so the screen is
 * usable with the browser alone: navigating weeks and choosing a shift are both
 * links, and JavaScript only makes them feel quicker.
 */
final readonly class GetSwapComposerCalendar
{
    public function __construct(
        public string $workerId,
        public string $requestId,
        public int $weekOffset = 0,
        public ?string $selectedShiftKey = null,
    ) {
    }
}
