<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Notification;

use App\Notification\Domain\AuthenticatedNotificationRecipient;
use Doctrine\DBAL\Connection;

final readonly class IdentityNotificationRecipient implements AuthenticatedNotificationRecipient
{
    public function __construct(private Connection $connection)
    {
    }

    public function idForEmail(string $email): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM identity_users WHERE email = :email', ['email' => mb_strtolower(trim($email))]);

        return \is_string($id) ? $id : null;
    }
}
