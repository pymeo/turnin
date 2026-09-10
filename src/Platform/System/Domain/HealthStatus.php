<?php

declare(strict_types=1);

namespace App\Platform\System\Domain;

/**
 * How well the system is currently able to do its job.
 *
 * The three values are ordered: `Unhealthy` beats `Degraded` beats `Healthy`
 * when several components disagree.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';

    /**
     * The worse of the two statuses.
     */
    public function worseOf(self $other): self
    {
        return $this->severity() >= $other->severity() ? $this : $other;
    }

    /**
     * Whether the system can still serve requests.
     *
     * `Degraded` deliberately counts as serviceable: losing the cache slows
     * Turnin down, it does not make shift data wrong.
     */
    public function isServiceable(): bool
    {
        return self::Unhealthy !== $this;
    }

    private function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Degraded => 1,
            self::Unhealthy => 2,
        };
    }
}
