<?php

declare(strict_types=1);

namespace App\Platform\System\Application\Query;

/**
 * Ask every registered probe how its dependency is doing.
 *
 * Carries no arguments today; it exists as a type so that the query bus, the
 * HTTP adapter and the console adapter all go through the same use case.
 */
final readonly class CheckSystemHealth
{
}
