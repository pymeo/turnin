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
}
