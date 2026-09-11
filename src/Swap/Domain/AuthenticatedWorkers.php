<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Who is signed in, resolved to a worker id. The same shape Scheduling and
 * Workforce each declare for themselves: four lines of adapter is a smaller
 * price than a context importing another context's port.
 */
interface AuthenticatedWorkers
{
    public function idForEmail(string $email): ?string;
}
