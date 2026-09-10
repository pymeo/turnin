<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Persistence\Doctrine;

use App\Platform\Identity\Domain\Email;
use App\Platform\Identity\Domain\ExternalIdentities;
use App\Platform\Identity\Domain\ExternalIdentity;
use App\Platform\Identity\Domain\ExternalIdentityProvider;
use App\Platform\Identity\Domain\UserId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineExternalIdentities implements ExternalIdentities
{
    public function __construct(private Connection $connection)
    {
    }

    public function byProviderSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_external_identities WHERE provider = :provider AND provider_subject = :subject', ['provider' => $provider->value, 'subject' => $subject]);

        return false === $row ? null : $this->map($row);
    }

    public function byUserAndProvider(UserId $userId, ExternalIdentityProvider $provider): ?ExternalIdentity
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_external_identities WHERE user_id = :user_id AND provider = :provider', ['user_id' => $userId->value, 'provider' => $provider->value]);

        return false === $row ? null : $this->map($row);
    }

    public function save(ExternalIdentity $identity): void
    {
        $this->connection->insert('identity_external_identities', ['id' => $identity->id, 'user_id' => $identity->userId->value, 'provider' => $identity->provider->value, 'provider_subject' => $identity->providerSubject, 'email_at_link_time' => $identity->emailAtLinkTime->value, 'created_at' => $identity->createdAt], ['created_at' => 'datetime_immutable']);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ExternalIdentity
    {
        return new ExternalIdentity($this->text($row['id'] ?? null), new UserId($this->text($row['user_id'] ?? null)), ExternalIdentityProvider::from($this->text($row['provider'] ?? null)), $this->text($row['provider_subject'] ?? null), new Email($this->text($row['email_at_link_time'] ?? null)), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
