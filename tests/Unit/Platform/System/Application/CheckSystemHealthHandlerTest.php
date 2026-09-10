<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\System\Application;

use App\Platform\System\Application\Query\CheckSystemHealth;
use App\Platform\System\Application\Query\CheckSystemHealthHandler;
use App\Platform\System\Domain\ComponentCriticality;
use App\Platform\System\Domain\ComponentHealth;
use App\Platform\System\Domain\ComponentName;
use App\Platform\System\Domain\HealthProbe;
use App\Platform\System\Domain\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The handler runs without a container, a database or a network: every probe is
 * a port, and time comes from the clock port. That is the whole point of the
 * dependency direction.
 */
#[CoversClass(CheckSystemHealthHandler::class)]
final class CheckSystemHealthHandlerTest extends TestCase
{
    public function test_it_reports_every_probe_it_was_given(): void
    {
        $handler = new CheckSystemHealthHandler(
            [
                self::probeReporting(ComponentHealth::up(new ComponentName('database'), ComponentCriticality::Required)),
                self::probeReporting(ComponentHealth::up(new ComponentName('cache'), ComponentCriticality::Optional)),
            ],
            new MockClock('2026-01-17T22:00:00+00:00'),
        );

        $report = $handler(new CheckSystemHealth());

        self::assertCount(2, $report->components);
        self::assertSame(HealthStatus::Healthy, $report->status());
    }

    public function test_it_stamps_the_report_with_the_clock_rather_than_the_wall_time(): void
    {
        $handler = new CheckSystemHealthHandler(
            [self::probeReporting(ComponentHealth::up(new ComponentName('cache'), ComponentCriticality::Optional))],
            new MockClock('2026-01-17T22:00:00+00:00'),
        );

        $report = $handler(new CheckSystemHealth());

        self::assertSame('2026-01-17T22:00:00+00:00', $report->observedAt->format(\DATE_RFC3339));
    }

    public function test_a_failing_dependency_surfaces_in_the_overall_status(): void
    {
        $handler = new CheckSystemHealthHandler(
            [
                self::probeReporting(ComponentHealth::up(new ComponentName('cache'), ComponentCriticality::Optional)),
                self::probeReporting(ComponentHealth::down(new ComponentName('database'), ComponentCriticality::Required, 'unreachable')),
            ],
            new MockClock('2026-01-17T22:00:00+00:00'),
        );

        $report = $handler(new CheckSystemHealth());

        self::assertSame(HealthStatus::Unhealthy, $report->status());
    }

    private static function probeReporting(ComponentHealth $result): HealthProbe
    {
        return new readonly class($result) implements HealthProbe {
            public function __construct(private ComponentHealth $result)
            {
            }

            public function check(): ComponentHealth
            {
                return $this->result;
            }
        };
    }
}
