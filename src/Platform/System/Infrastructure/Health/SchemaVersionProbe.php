<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Health;

use App\Platform\System\Domain\ComponentCriticality;
use App\Platform\System\Domain\ComponentHealth;
use App\Platform\System\Domain\ComponentName;
use App\Platform\System\Domain\HealthProbe;
use Doctrine\Migrations\DependencyFactory;
use Throwable;

/**
 * Whether the database schema is at the version this build expects.
 *
 * Turnin deploys schema-first: migrations run, then containers roll. A container
 * that finds pending migrations is running against a schema it was not built
 * for, so it reports Unhealthy and the orchestrator keeps traffic away from it.
 */
final readonly class SchemaVersionProbe implements HealthProbe
{
    public function __construct(private DependencyFactory $migrations)
    {
    }

    public function check(): ComponentHealth
    {
        $name = new ComponentName('schema');

        try {
            $pending = $this->migrations->getMigrationStatusCalculator()->getNewMigrations();
        } catch (Throwable) {
            return ComponentHealth::down($name, ComponentCriticality::Required, 'unknown');
        }

        if (0 !== \count($pending)) {
            return ComponentHealth::down($name, ComponentCriticality::Required, 'pending_migrations');
        }

        return ComponentHealth::up($name, ComponentCriticality::Required);
    }
}
