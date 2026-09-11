<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class OpenSwapRequest
{
    /**
     * @param string|null $swapPoolId null means "the group this calendar
     *                                belongs to", the only choice most people
     *                                have
     */
    public function __construct(
        public string $workerId,
        public string $workerAssignmentId,
        public string $date,
        public ?string $swapPoolId = null,
    ) {
    }
}
