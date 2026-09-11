<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Persistence\Doctrine;

use App\Platform\Identity\Domain\IdentityEvidence;
use App\Platform\Identity\Domain\PersonalIdentityAlreadyUsed;
use App\Platform\Identity\Domain\UsageIdentities;
use App\Platform\Identity\Domain\UsageIdentity;
use App\Platform\Identity\Domain\UserId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DoctrineUsageIdentities implements UsageIdentities
{
    public function __construct(private Connection $connection)
    {
    }

    public function byUserId(UserId $userId): ?UsageIdentity
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_usage_identities WHERE user_id = :user', ['user' => $userId->value]);

        return false === $row ? null : new UsageIdentity($this->text($row['id'] ?? null), new UserId($this->text($row['user_id'] ?? null)), $this->text($row['identity_document_fingerprint'] ?? null), $this->text($row['phone_fingerprint'] ?? null), IdentityEvidence::from($this->text($row['evidence'] ?? null)), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
    }

    public function save(UsageIdentity $identity): void
    {
        try {
            $this->connection->insert('identity_usage_identities', [
                'id' => $identity->id,
                'user_id' => $identity->userId->value,
                'identity_document_fingerprint' => $identity->identityDocumentFingerprint,
                'phone_fingerprint' => $identity->phoneFingerprint,
                'evidence' => $identity->evidence->value,
                'created_at' => $identity->createdAt,
            ], ['created_at' => 'datetime_immutable']);
        } catch (UniqueConstraintViolationException $exception) {
            throw new PersonalIdentityAlreadyUsed('Estos datos identificativos ya están asociados a otra cuenta.', 0, $exception);
        }
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
