<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

/**
 * Exactly what the confirmation screen shows, and exactly what the writer will
 * do. Never a summary computed separately from the thing being confirmed.
 */
final readonly class ScheduleDraftPreview
{
    /**
     * @param list<DraftEntryView> $entries
     * @param list<string>         $unrecognized
     */
    public function __construct(
        public array $entries,
        public int $totalDays,
        public int $shiftCount,
        public int $restCount,
        public int $conflictCount,
        public int $applyCount,
        public string $policy,
        public array $unrecognized,
        public ?string $from,
        public ?string $to,
    ) {
    }
}
