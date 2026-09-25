<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Workforce;

use App\Workforce\Domain\Supervision\SupervisorProfiles;
use Doctrine\DBAL\Connection;

/**
 * Enough for a colleague to recognise a candidate, and nothing more: the full
 * name the person gave Google (or the onboarding) and an email with most of
 * its local part hidden. Phone and identity document never leave Identity.
 */
final readonly class IdentitySupervisorProfiles implements SupervisorProfiles
{
    public function __construct(private Connection $connection)
    {
    }

    public function displayName(string $userId): string
    {
        $row = $this->connection->fetchAssociative('SELECT given_name, family_name FROM identity_personal_profiles WHERE user_id = :id', ['id' => $userId]);
        $name = false === $row ? '' : trim($this->text($row['given_name'] ?? null).' '.$this->text($row['family_name'] ?? null));

        return '' !== $name ? $name : $this->maskedEmail($userId);
    }

    public function maskedEmail(string $userId): string
    {
        $email = $this->text($this->connection->fetchOne('SELECT email FROM identity_users WHERE id = :id', ['id' => $userId]));
        $at = strrpos($email, '@');
        if (false === $at) {
            return 'Cuenta de Google';
        }

        return mb_substr(substr($email, 0, $at), 0, 3).'***'.substr($email, $at);
    }

    public function markSupervisorProfile(string $userId): void
    {
        $this->connection->executeStatement('UPDATE identity_users SET has_supervisor_profile = TRUE WHERE id = :id', ['id' => $userId]);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
