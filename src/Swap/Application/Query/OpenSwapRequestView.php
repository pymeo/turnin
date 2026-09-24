<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * A colleague's shift, as a card.
 *
 * It carries a given name and nothing else about the person — an email or a
 * phone number here would be a privacy leak dressed as a feature — and it
 * carries the answer to the only question the card exists to raise: can I do
 * this one. The template never works that out; it is decided on the server
 * against real shift intervals.
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
        public bool $canCover,
        /** Null when it can be covered; otherwise a {@see \App\Swap\Domain\ShiftObstacle} value. */
        public ?string $obstacle,
        public string $obstacleMessage,
        /** Secondary: the worker had already said this day suited them. */
        public bool $alreadyAvailable,
    ) {
    }
}
