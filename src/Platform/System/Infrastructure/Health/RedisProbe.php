<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Health;

use App\Platform\System\Domain\ComponentCriticality;
use App\Platform\System\Domain\ComponentHealth;
use App\Platform\System\Domain\ComponentName;
use App\Platform\System\Domain\HealthProbe;
use Redis;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Throwable;

/**
 * Redis reachability.
 *
 * It talks to Redis directly rather than through the PSR-6 pool on purpose:
 * Symfony's cache adapters are built to survive a dead backend, so they answer
 * "cache miss" instead of failing — which would make this probe report a healthy
 * cache while Redis is down.
 *
 * Optional on purpose: a cold cache makes Turnin slower, not wrong.
 */
final readonly class RedisProbe implements HealthProbe
{
    /**
     * Short timeouts: a health check must answer quickly even when the thing it
     * checks is hanging.
     */
    private const OPTIONS = [
        'timeout' => 1,
        'read_timeout' => 1,
        'retry_interval' => 0,
    ];

    public function __construct(private string $dsn)
    {
    }

    public function check(): ComponentHealth
    {
        $name = new ComponentName('cache');

        try {
            $connection = RedisAdapter::createConnection($this->dsn, self::OPTIONS);

            if (!$connection instanceof Redis) {
                // Turnin is configured for a single Redis instance over the redis
                // extension. Anything else means the DSN and this probe disagree.
                return ComponentHealth::down($name, ComponentCriticality::Optional, 'unsupported_client');
            }

            $connection->ping();
        } catch (Throwable) {
            return ComponentHealth::down($name, ComponentCriticality::Optional, 'unreachable');
        }

        return ComponentHealth::up($name, ComponentCriticality::Optional);
    }
}
