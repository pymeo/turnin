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

    /** The headline number: "+17 h", "−3 h 30 min", "Mismas horas". */
    public function label(): string
    {
        if (0 === $this->minutes) {
            return 'Mismas horas';
        }

        return ($this->minutes > 0 ? '+' : '−').ShiftDuration::label($this->minutes);
    }

    /** The same fact as a sentence, for the people who read that instead. */
    public function hint(): string
    {
        return match (true) {
            $this->minutes > 0 => 'Trabajarías '.ShiftDuration::label($this->minutes).' más',
            $this->minutes < 0 => 'Trabajarías '.ShiftDuration::label($this->minutes).' menos',
            default => 'Das y recibes las mismas horas',
        };
    }
}
