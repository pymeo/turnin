<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class RosterShiftSegmentView
{
    public function __construct(
        public string $start,
        public string $end,
        public int $durationMinutes,
        public string $duration,
        public string $label,
        public string $abbreviation,
        public string $color,
        public string $source,
        public ?RosterSwapRole $swapRole = null,
        public ?string $colleagueDisplayName = null,
        public ?string $agreementId = null,
        public ?RosterAgreementStatus $agreementStatus = null,
    ) {
    }
}
