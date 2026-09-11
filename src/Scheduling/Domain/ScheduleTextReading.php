<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * What the parser made of one piece of dictated or typed text: the days it
 * understood, the rotation it recognised if the worker described one, and —
 * just as important — the fragments it did not understand.
 *
 * Unrecognised fragments are returned rather than silently dropped. Applying
 * three of five clauses and saying nothing about the other two is the failure
 * mode that makes people stop trusting dictation.
 */
final readonly class ScheduleTextReading
{
    /** @param list<string> $unrecognized */
    public function __construct(public ScheduleDraft $draft, public ?PatternDraft $pattern, public array $unrecognized)
    {
    }

    public function understoodNothing(): bool
    {
        return $this->draft->isEmpty() && (null === $this->pattern || $this->pattern->isEmpty());
    }
}
