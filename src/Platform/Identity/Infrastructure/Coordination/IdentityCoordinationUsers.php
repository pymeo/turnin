<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Coordination;

use App\Coordination\Application\Query\CoordinationDisplayNames;
use App\Coordination\Application\Query\CurrentCoordinationUser;
use App\Platform\Identity\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class IdentityCoordinationUsers implements CoordinationDisplayNames, CurrentCoordinationUser
{
    public function __construct(private Connection $connection, private TokenStorageInterface $tokens)
    {
    }

    /** @return array<string, string> */
    public function forUsers(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT user_id, given_name FROM identity_personal_profiles WHERE user_id IN (:ids)', ['ids' => $userIds], ['ids' => ArrayParameterType::STRING]);
        $names = [];
        foreach ($rows as $row) {
            $name = trim($this->text($row['given_name'] ?? null));
            if ('' !== $name) {
                $names[$this->text($row['user_id'] ?? null)] = $name;
            }
        }

        return $names;
    }

    public function id(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof SecurityUser ? $user->id() : null;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
