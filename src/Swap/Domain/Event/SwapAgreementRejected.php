<?php

declare(strict_types=1);

namespace App\Swap\Domain\Event;

final readonly class SwapAgreementRejected implements SwapEvent
{
    public function __construct(public string $proposalId, public string $requestOwnerId, public string $proposerId, public string $requestedDate)
    {
    }

    public function eventId(): string
    {
        return 'swap-agreement:'.$this->proposalId.':rejected';
    }
}
