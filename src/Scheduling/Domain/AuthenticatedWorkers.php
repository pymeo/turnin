<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Who is signed in, resolved to a worker id.
 *
 * The same shape Workforce declares for itself. It is deliberately not shared:
 * a context that imported another context's port would be one refactor away
 * from importing its model too, and the implementation is four lines.
 */
interface AuthenticatedWorkers
{
    public function idForEmail(string $email): ?string;
}
