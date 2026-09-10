<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Health;

use App\Platform\System\Domain\ComponentCriticality;
use App\Platform\System\Domain\ComponentHealth;
use App\Platform\System\Domain\ComponentName;
use App\Platform\System\Domain\HealthProbe;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * PostgreSQL reachability.
 *
 * The database is where Turnin's critical invariants ultimately live, so losing
 * it is fatal rather than degrading.
 */
final readonly class DatabaseProbe implements HealthProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function check(): ComponentHealth
    {
        $name = new ComponentName('database');

        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (Throwable) {
            // The exception message would carry the DSN, user name and host.
            // `/health` is unauthenticated: report the fact, not the details.
            return ComponentHealth::down($name, ComponentCriticality::Required, 'unreachable');
        }

        return ComponentHealth::up($name, ComponentCriticality::Required);
    }
}
