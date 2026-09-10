<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\System\Domain;

use App\Platform\System\Domain\ComponentCriticality;
use App\Platform\System\Domain\ComponentHealth;
use App\Platform\System\Domain\ComponentName;
use App\Platform\System\Domain\HealthStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComponentHealth::class)]
final class ComponentHealthTest extends TestCase
{
    public function test_a_reachable_component_is_healthy_whatever_its_criticality(): void
    {
        foreach (ComponentCriticality::cases() as $criticality) {
            $component = ComponentHealth::up(new ComponentName('database'), $criticality);

            self::assertSame(HealthStatus::Healthy, $component->status());
            self::assertNull($component->detail);
        }
    }

    public function test_losing_a_required_component_makes_the_system_unhealthy(): void
    {
        $component = ComponentHealth::down(
            new ComponentName('database'),
            ComponentCriticality::Required,
            'unreachable',
        );

        self::assertSame(HealthStatus::Unhealthy, $component->status());
        self::assertSame('unreachable', $component->detail);
    }

    public function test_losing_an_optional_component_only_degrades_the_system(): void
    {
        $component = ComponentHealth::down(
            new ComponentName('cache'),
            ComponentCriticality::Optional,
            'unreachable',
        );

        self::assertSame(HealthStatus::Degraded, $component->status());
    }

    public function test_a_failure_must_say_something_useful(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentHealth::down(new ComponentName('cache'), ComponentCriticality::Optional, '   ');
    }
}
