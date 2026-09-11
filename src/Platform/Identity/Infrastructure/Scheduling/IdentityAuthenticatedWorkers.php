<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Scheduling;

use App\Scheduling\Domain\AuthenticatedWorkers;
use Doctrine\DBAL\Connection;

/**
 * Identity answering Scheduling's question about the session, the same way it
 * already answers Workforce's.
 */
final readonly class IdentityAuthenticatedWorkers implements AuthenticatedWorkers
{
    public function __construct(private Connection $connection)
    {
    }

    public function idForEmail(string $email): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM identity_users WHERE email = :email', ['email' => $email]);

        return \is_string($id) ? $id : null;
    }
}
