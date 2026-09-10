<?php

declare(strict_types=1);

namespace App\Platform\System\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A complete observation of the system at one instant.
 *
 * Invariants: at least one component was checked, and no component is reported
 * twice — a duplicate name would silently hide one of the two results.
 */
final readonly class HealthReport
{
    /** @var list<ComponentHealth> */
    public array $components;

    /**
     * @param list<ComponentHealth> $components
     */
    public function __construct(array $components, public DateTimeImmutable $observedAt)
    {
        if ([] === $components) {
            throw new InvalidArgumentException('A health report must cover at least one component.');
        }

        $seen = [];
        foreach ($components as $component) {
            $name = $component->name->value;
            if (isset($seen[$name])) {
                throw new InvalidArgumentException(\sprintf('Component "%s" is reported twice.', $name));
            }
            $seen[$name] = true;
        }

        // Stable output regardless of the order probes were registered in, so
        // that dashboards and snapshot assertions do not flap.
        usort($components, static fn (ComponentHealth $a, ComponentHealth $b): int => $a->name->value <=> $b->name->value);

        $this->components = $components;
    }

    public function status(): HealthStatus
    {
        $status = HealthStatus::Healthy;
        foreach ($this->components as $component) {
            $status = $status->worseOf($component->status());
        }

        return $status;
    }

    public function isServiceable(): bool
    {
        return $this->status()->isServiceable();
    }
}
