<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

/**
 * "Se lo hago": I will do the shift you published, and here are one to five of
 * mine — pick whichever you can.
 */
final readonly class CreateSwapProposal
{
    /** @param list<string> $offeredShiftKeys assignmentId|date pairs, as the screen posts them */
    public function __construct(
        public string $workerId,
        public string $requestId,
        public array $offeredShiftKeys,
    ) {
    }
}
