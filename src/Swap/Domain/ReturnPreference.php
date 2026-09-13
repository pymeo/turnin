<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use InvalidArgumentException;

final readonly class ReturnPreference
{
    /** @param list<int> $preferredWeekdays ISO-8601, 1 Monday through 7 Sunday */
    public function __construct(
        public ?string $month = null,
        public ?ShiftKind $shiftKind = null,
        public ?int $durationMinutes = null,
        public array $preferredWeekdays = [],
    ) {
        if (null !== $this->month && 1 !== preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            throw new InvalidArgumentException('El periodo de devolución no es válido.');
        }
        if (null !== $this->durationMinutes && $this->durationMinutes <= 0) {
            throw new InvalidArgumentException('La duración preferida debe ser positiva.');
        }
        foreach ($this->preferredWeekdays as $weekday) {
            if ($weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('El día preferido no es válido.');
            }
        }
    }

    public function isEmpty(): bool
    {
        return null === $this->month && null === $this->shiftKind && null === $this->durationMinutes && [] === $this->preferredWeekdays;
    }

    /** @return array{int, list<string>} score and human-readable reasons */
    public function match(WorkDate $date, ShiftKind $kind, int $durationMinutes): array
    {
        $score = 0;
        $reasons = [];
        if (null !== $this->month && str_starts_with((string) $date, $this->month.'-')) {
            $score += OpportunityScoreWeights::PREFERRED_MONTH;
            $reasons[] = 'coincide con el periodo que pediste';
        }
        if (null !== $this->shiftKind && $this->shiftKind === $kind) {
            $score += OpportunityScoreWeights::PREFERRED_KIND;
            $reasons[] = 'coincide con la franja que pediste';
        }
        if (null !== $this->durationMinutes && $this->durationMinutes === $durationMinutes) {
            $score += OpportunityScoreWeights::PREFERRED_DURATION;
            $reasons[] = 'coincide con la duración que pediste';
        }
        if ([] !== $this->preferredWeekdays && \in_array($date->dayOfWeek(), $this->preferredWeekdays, true)) {
            $score += OpportunityScoreWeights::PREFERRED_WEEKDAY;
            $reasons[] = 'coincide con uno de tus días preferidos';
        }

        return [$score, $reasons];
    }
}
