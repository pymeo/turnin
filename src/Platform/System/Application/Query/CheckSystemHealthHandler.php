<?php

declare(strict_types=1);

namespace App\Platform\System\Application\Query;

use App\Platform\System\Domain\HealthProbe;
use App\Platform\System\Domain\HealthReport;
use Psr\Clock\ClockInterface;

/**
 * Runs the probes and assembles the report.
 *
 * The handler owns no rules of its own: deciding what a failure means lives in
 * the domain (ComponentHealth::status(), HealthReport::status()).
 */
final readonly class CheckSystemHealthHandler
{
    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(
        private iterable $probes,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckSystemHealth $query): HealthReport
    {
        $components = [];
        foreach ($this->probes as $probe) {
            $components[] = $probe->check();
        }

        return new HealthReport($components, $this->clock->now());
    }
}
