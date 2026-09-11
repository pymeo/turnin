<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class DeclareAvailability
{
    /**
     * @param list<string> $swapPoolIds empty means "the groups this calendar
     *                                  reaches", which covers the common case
     *                                  of somebody with a single destination
     */
    public function __construct(
        public string $workerId,
        public string $workerAssignmentId,
        public string $date,
        public array $swapPoolIds = [],
        /** @var list<string> Empty means every basic kind (the UI's “Cualquier turno”). */
        public array $shiftKinds = [],
    ) {
    }
}
