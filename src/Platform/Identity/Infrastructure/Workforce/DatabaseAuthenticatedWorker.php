<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Workforce;

use App\Workforce\Domain\AuthenticatedWorker;
use Doctrine\DBAL\Connection;

final readonly class DatabaseAuthenticatedWorker implements AuthenticatedWorker
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
