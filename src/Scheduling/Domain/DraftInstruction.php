<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * What the interface asked for, before it is resolved into a draft: a date, an
 * intent and — for a working day — which of the worker's own presets to apply.
 *
 * Presets are referenced by id and nothing else. The browser never sends hours
 * or labels, so a segment can only ever hold a snapshot of a preset this worker
 * owns, and ownership is checked in one place.
 */
final readonly class DraftInstruction
{
    /** @param list<string> $presetIds */
    public function __construct(public WorkDate $date, public DraftIntent $intent, public array $presetIds = [])
    {
    }
}
