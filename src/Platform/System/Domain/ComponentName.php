<?php

declare(strict_types=1);

namespace App\Platform\System\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * The identity of a checked component, as it appears in `/health` output.
 *
 * Names are part of an operational contract (dashboards and alerts match on
 * them), so the shape is constrained instead of accepting any string.
 */
final readonly class ComponentName implements Stringable
{
    private const PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    public function __construct(public string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidArgumentException(\sprintf('Component name "%s" must be 2-32 lowercase letters, digits or underscores and start with a letter.', $value));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
