<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;

/**
 * One proposed day. Warnings travel with the entry rather than in a separate
 * list so the preview can point at the exact date that is in doubt.
 */
final readonly class ScheduleDraftEntry
{
    /**
     * @param list<SegmentProposal> $segments
     * @param list<string>          $warnings
     */
    public function __construct(public WorkDate $date, public DraftIntent $intent, public array $segments = [], public array $warnings = [])
    {
        if (DraftIntent::WORK === $this->intent && [] === $this->segments && [] === $this->warnings) {
            throw new InvalidArgumentException('A working draft entry needs at least one segment.');
        }
        if (DraftIntent::WORK !== $this->intent && [] !== $this->segments) {
            throw new InvalidArgumentException('Only a working draft entry carries segments.');
        }
    }

    /** @param list<SegmentProposal> $segments */
    public static function work(WorkDate $date, array $segments): self
    {
        return new self($date, DraftIntent::WORK, $segments);
    }

    public static function rest(WorkDate $date): self
    {
        return new self($date, DraftIntent::REST);
    }

    public static function clear(WorkDate $date): self
    {
        return new self($date, DraftIntent::CLEAR);
    }

    /** A day we could not resolve: shown in the preview, never written. */
    public static function unresolved(WorkDate $date, string $warning): self
    {
        return new self($date, DraftIntent::WORK, [], [$warning]);
    }

    public function isApplicable(): bool
    {
        return DraftIntent::WORK !== $this->intent || [] !== $this->segments;
    }

    public function abbreviation(): string
    {
        return match ($this->intent) {
            DraftIntent::REST => 'L',
            DraftIntent::CLEAR => '·',
            DraftIntent::WORK => $this->segments[0]->abbreviation ?? '?',
        };
    }

    public function describe(): string
    {
        return match ($this->intent) {
            DraftIntent::REST => 'Libre',
            DraftIntent::CLEAR => 'Sin información',
            DraftIntent::WORK => implode(' · ', array_map(static fn (SegmentProposal $segment): string => \sprintf('%s %s', $segment->label, $segment->window), $this->segments)),
        };
    }
}
