<?php

declare(strict_types=1);

namespace App\Swap\Domain\Event;

final readonly class SwapAgreementReached implements SwapEvent
{
    public function __construct(
        public string $proposalId,
        public string $requestOwnerId,
        public string $proposerId,
        public string $requestOwnerName,
        public string $proposerName,
        public string $requestedDate,
        public bool $requiresApproval,
    ) {
    }

    public function eventId(): string
    {
        return 'swap-agreement:'.$this->proposalId.':reached';
    }
}
