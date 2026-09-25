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
        public string $swapPoolId = '',
        /** @var list<string> verified supervisors to notify when approval is required */
        public array $approverIds = [],
    ) {
    }

    public function eventId(): string
    {
        return 'swap-agreement:'.$this->proposalId.':reached';
    }
}
