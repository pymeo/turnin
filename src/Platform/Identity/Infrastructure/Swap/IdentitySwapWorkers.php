<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Swap;

use App\Swap\Domain\AuthenticatedWorkers;
use App\Swap\Domain\WorkerDisplayNames;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Identity answering the two questions Swap has about people: who is signed in,
 * and what to call somebody on a card.
 *
 * Only the given name leaves this class. The surname, the phone and the
 * identity document stay where docs/SECURITY.md put them, and a swap screen has
 * no reason for any of them.
 */
final readonly class IdentitySwapWorkers implements AuthenticatedWorkers, WorkerDisplayNames
{
    public function __construct(private Connection $connection)
    {
    }

    public function idForEmail(string $email): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM identity_users WHERE email = :email', ['email' => $email]);

        return \is_string($id) ? $id : null;
    }

    public function forWorkers(array $workerIds): array
    {
        if ([] === $workerIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT user_id, given_name FROM identity_personal_profiles WHERE user_id IN (:ids)',
            ['ids' => $workerIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $names = [];
        foreach ($rows as $row) {
            $name = trim($this->text($row['given_name'] ?? null));
            if ('' !== $name) {
                $names[$this->text($row['user_id'] ?? null)] = $name;
            }
        }

        return $names;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
