<?php

declare(strict_types=1);

namespace App\Swap\Domain;

final readonly class ShiftBalance
{
    private function __construct(public int $minutes)
    {
    }

    /** Positive means the professional works more; negative, less. */
    public static function forProposer(int $requestedShiftMinutes, int $offeredShiftMinutes): self
    {
        return new self($requestedShiftMinutes - $offeredShiftMinutes);
    }

    public function forRequestOwner(): self
    {
        return new self(-$this->minutes);
    }
}
