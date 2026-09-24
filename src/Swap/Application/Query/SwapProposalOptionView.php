<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * One of the shifts offered back, judged again the moment the screen is opened.
 * It was compatible when it was sent; a rota can change in between.
 */
final readonly class SwapProposalOptionView
{
    public function __construct(
        public string $optionId,
        public string $date,
        public string $dateHeadline,
        public string $dateCompact,
        public string $shiftLabel,
        public string $hours,
        public string $durationLabel,
        public bool $endsNextDay,
        public string $tone,
        public bool $canDoIt,
        public string $obstacleMessage,
    ) {
    }
}
