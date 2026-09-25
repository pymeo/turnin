<?php

declare(strict_types=1);

namespace App\Swap\Domain\Event;

final readonly class SwapProposalCreated implements SwapEvent
{
    public function __construct(
        public string $proposalId,
        public string $recipientId,
        public string $proposerName,
        public string $requestedDate,
        public int $optionCount,
    ) {
    }

    public function eventId(): string
    {
        return 'swap-proposal:'.$this->proposalId.':created';
    }
}
