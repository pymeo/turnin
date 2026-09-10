<?php

declare(strict_types=1);

namespace App\Platform\System\Domain;

use InvalidArgumentException;

/**
 * The outcome of probing one component.
 *
 * `detail` is a short, fixed token chosen by the probe — never an exception
 * message. Driver exceptions carry connection strings, and `/health` is
 * reachable without authentication (see docs/SECURITY.md).
 */
final readonly class ComponentHealth
{
    private function __construct(
        public ComponentName $name,
        public ComponentCriticality $criticality,
        public bool $reachable,
        public ?string $detail,
    ) {
    }

    public static function up(ComponentName $name, ComponentCriticality $criticality): self
    {
        return new self($name, $criticality, true, null);
    }

    public static function down(ComponentName $name, ComponentCriticality $criticality, string $detail): self
    {
        if ('' === trim($detail)) {
            throw new InvalidArgumentException('A failing component must explain itself with a non-empty detail.');
        }

        return new self($name, $criticality, false, $detail);
    }

    public function status(): HealthStatus
    {
        if ($this->reachable) {
            return HealthStatus::Healthy;
        }

        return match ($this->criticality) {
            ComponentCriticality::Required => HealthStatus::Unhealthy,
            ComponentCriticality::Optional => HealthStatus::Degraded,
        };
    }
}
