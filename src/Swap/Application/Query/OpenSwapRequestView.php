<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * A colleague's shift, as a card. Carries a given name and nothing else about
 * the person: an email or a phone number on this screen would be a privacy leak
 * dressed as a feature.
 */
final readonly class OpenSwapRequestView
{
    public function __construct(
        public string $requestId,
        public string $date,
        public string $dateHeadline,
        public string $shiftLabel,
        public string $abbreviation,
        public string $hours,
        public int $durationMinutes,
        public string $durationLabel,
        public bool $endsNextDay,
        public string $colorKey,
        public string $groupLabel,
        public string $workplaceName,
        public string $authorName,
        public bool $alreadyAvailable,
    ) {
    }
}
