<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Persistence\Doctrine;

use App\Platform\Identity\Domain\PersonalProfile;
use App\Platform\Identity\Domain\PersonalProfiles;
use App\Platform\Identity\Domain\UserId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrinePersonalProfiles implements PersonalProfiles
{
    public function __construct(private Connection $connection)
    {
    }

    public function byUserId(UserId $userId): ?PersonalProfile
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_personal_profiles WHERE user_id = :user', ['user' => $userId->value]);
        if (false === $row) {
            return null;
        }

        return $this->map($row);
    }

    public function save(PersonalProfile $profile): void
    {
        $this->connection->executeStatement('INSERT INTO identity_personal_profiles (user_id, given_name, family_name, phone_encrypted, identity_evidence, created_at, updated_at) VALUES (:user, :given, :family, :phone, :evidence, :created, :updated) ON CONFLICT (user_id) DO UPDATE SET given_name = EXCLUDED.given_name, family_name = EXCLUDED.family_name, phone_encrypted = EXCLUDED.phone_encrypted, identity_evidence = EXCLUDED.identity_evidence, updated_at = EXCLUDED.updated_at', [
            'user' => $profile->userId()->value,
            'given' => $profile->givenName(),
            'family' => $profile->familyName(),
            'phone' => $profile->phoneEncrypted(),
            'evidence' => $profile->identityEvidence()?->value,
            'created' => $profile->createdAt(),
            'updated' => $profile->updatedAt(),
        ], ['created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): PersonalProfile
    {
        $profile = PersonalProfile::start(new UserId($this->text($row['user_id'] ?? null)), $this->text($row['given_name'] ?? null), $this->text($row['family_name'] ?? null), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
        if (\is_string($row['phone_encrypted'] ?? null) && '' !== $row['phone_encrypted']) {
            $profile->protectPhone($row['phone_encrypted'], new DateTimeImmutable($this->text($row['updated_at'] ?? null)));
        }

        return $profile;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
