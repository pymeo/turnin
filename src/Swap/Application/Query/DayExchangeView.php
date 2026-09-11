<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * Everything the calendar's day sheet needs to offer the right action: whether
 * the day can be published, whether it already is, and which groups it could be
 * offered to.
 */
final readonly class DayExchangeView
{
    /**
     * @param list<SwapGroupView> $groups      groups this day could be offered to
     * @param list<string>        $availableIn pool ids the worker is already available in
     * @param list<CandidateView> $candidates  only ever for the worker's own published shift
     */
    public function __construct(
        public string $date,
        public string $state,
        public bool $canPublish,
        public bool $canOfferAvailability,
        public ?string $requestId,
        public int $candidateCount,
        public array $groups,
        public array $availableIn,
        public array $candidates,
    ) {
    }
}
