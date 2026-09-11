<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * What to do with days the draft touches that already have information.
 *
 * An explicit enum rather than a boolean: `apply($draft, true)` at a call site
 * reads as nothing at all, and the difference between the two values is a month
 * of somebody's hand-entered roster.
 */
enum ConflictPolicy: string
{
    /** The default everywhere. What the worker already told us wins. */
    case SKIP_EXISTING = 'skip_existing';

    /** Only ever after an explicit confirmation in the interface. */
    case REPLACE_EXISTING = 'replace_existing';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::SKIP_EXISTING;
    }
}
