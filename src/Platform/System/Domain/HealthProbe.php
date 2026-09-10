<?php

declare(strict_types=1);

namespace App\Platform\System\Domain;

/**
 * Port implemented by each adapter that can report on a dependency.
 *
 * A probe never throws: an unreachable dependency is a result, not an error.
 */
interface HealthProbe
{
    public function check(): ComponentHealth;
}
