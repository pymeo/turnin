<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Declared by Swap, implemented by Identity.
 *
 * A card says "Pedro quiere quitarse este turno" and stops there. Identity
 * holds the email, the phone and the identity document under a stricter privacy
 * regime (docs/SECURITY.md); this port is deliberately narrow so there is no
 * way for any of that to arrive here by accident.
 */
interface WorkerDisplayNames
{
    /**
     * @param list<string> $workerIds
     *
     * @return array<string, string> worker id → given name, missing ones absent
     */
    public function forWorkers(array $workerIds): array;
}
