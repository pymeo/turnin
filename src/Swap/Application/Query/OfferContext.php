<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * What "Puedo hacerlo" needs before it can declare anything: which of *my*
 * assignments reaches that group, and which day.
 */
final readonly class OfferContext
{
    public function __construct(
        public string $requestId,
        public string $swapPoolId,
        public string $assignmentId,
        public string $date,
        public string $shiftKind,
        public bool $alreadyAvailable,
    ) {
    }
}
