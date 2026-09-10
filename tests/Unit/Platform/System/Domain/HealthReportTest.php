<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\System\Domain;

use App\Platform\System\Domain\ComponentCriticality;
use App\Platform\System\Domain\ComponentHealth;
use App\Platform\System\Domain\ComponentName;
use App\Platform\System\Domain\HealthReport;
use App\Platform\System\Domain\HealthStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthReport::class)]
final class HealthReportTest extends TestCase
{
    public function test_the_overall_status_is_the_worst_component_status(): void
    {
        $report = new HealthReport([
            self::up('cache', ComponentCriticality::Optional),
            self::down('database', ComponentCriticality::Required),
            self::up('schema', ComponentCriticality::Required),
        ], self::anInstant());

        self::assertSame(HealthStatus::Unhealthy, $report->status());
        self::assertFalse($report->isServiceable());
    }

    public function test_a_single_optional_outage_leaves_the_system_serviceable(): void
    {
        $report = new HealthReport([
            self::down('cache', ComponentCriticality::Optional),
            self::up('database', ComponentCriticality::Required),
        ], self::anInstant());

        self::assertSame(HealthStatus::Degraded, $report->status());
        self::assertTrue($report->isServiceable());
    }

    public function test_components_are_ordered_by_name_regardless_of_probe_registration_order(): void
    {
        $report = new HealthReport([
            self::up('schema', ComponentCriticality::Required),
            self::up('cache', ComponentCriticality::Optional),
            self::up('database', ComponentCriticality::Required),
        ], self::anInstant());

        $names = array_map(static fn (ComponentHealth $c): string => $c->name->value, $report->components);

        self::assertSame(['cache', 'database', 'schema'], $names);
    }

    public function test_it_remembers_when_it_was_observed(): void
    {
        $observedAt = self::anInstant();

        $report = new HealthReport([self::up('cache', ComponentCriticality::Optional)], $observedAt);

        self::assertSame($observedAt, $report->observedAt);
    }

    public function test_a_report_covering_nothing_is_meaningless(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthReport([], self::anInstant());
    }

    public function test_a_duplicated_component_would_hide_one_of_the_two_results(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthReport([
            self::up('database', ComponentCriticality::Required),
            self::down('database', ComponentCriticality::Required),
        ], self::anInstant());
    }

    private static function up(string $name, ComponentCriticality $criticality): ComponentHealth
    {
        return ComponentHealth::up(new ComponentName($name), $criticality);
    }

    private static function down(string $name, ComponentCriticality $criticality): ComponentHealth
    {
        return ComponentHealth::down(new ComponentName($name), $criticality, 'unreachable');
    }

    private static function anInstant(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-17T22:00:00+00:00');
    }
}
