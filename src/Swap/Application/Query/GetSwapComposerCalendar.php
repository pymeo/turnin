<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * "I will do your shift — now which of mine could you do for me?".
 *
 * The week being shown and the shifts already ticked travel in the query so the
 * screen works with nothing but a browser: paging is a link and every selection
 * survives it.
 */
final readonly class GetSwapComposerCalendar
{
    /** @param list<string> $selectedKeys assignmentId|date pairs already chosen */
    public function __construct(
        public string $workerId,
        public string $requestId,
        public int $weekOffset = 0,
        public array $selectedKeys = [],
    ) {
    }
}
