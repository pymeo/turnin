<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use InvalidArgumentException;

/**
 * One of the shifts the proposer offers back. The person who published the
 * request picks exactly one of them.
 *
 * It names a roster day and not a segment because a day is the unit the
 * calendars actually exchange: covering a day means covering all of it.
 */
final readonly class SwapProposalOption
{
    public function __construct(
        public string $id,
        public string $assignmentId,
        public string $rosterDayId,
        public WorkDate $workDate,
    ) {
        foreach ([$this->id, $this->assignmentId, $this->rosterDayId] as $reference) {
            if ('' === trim($reference)) {
                throw new InvalidArgumentException('Una opción de intercambio necesita un turno real.');
            }
        }
    }

    /** What identifies the same shift across the screen, the form and the roster. */
    public function key(): string
    {
        return $this->assignmentId.'|'.$this->workDate;
    }
}
