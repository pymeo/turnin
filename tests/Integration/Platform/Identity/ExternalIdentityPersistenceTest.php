<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform\Identity;

use App\Platform\Identity\Domain\Email;
use App\Platform\Identity\Domain\ExternalIdentity;
use App\Platform\Identity\Domain\ExternalIdentityProvider;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserId;
use App\Platform\Identity\Infrastructure\Persistence\Doctrine\DoctrineExternalIdentities;
use App\Platform\Identity\Infrastructure\Persistence\Doctrine\DoctrineUsers;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineExternalIdentities::class)]
final class ExternalIdentityPersistenceTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_google_only_user_and_external_identity_round_trip_without_tokens(): void
    {
        $users = new DoctrineUsers($this->connection);
        $identities = new DoctrineExternalIdentities($this->connection);
        $user = new User(new UserId('019b1000-0000-7000-8000-000000000001'), new Email('google@example.test'), null, false, false, new DateTimeImmutable('2026-09-10T12:00:00Z'));
        $users->save($user);
        $identity = new ExternalIdentity('019b1000-0000-7000-8000-000000000002', $user->id, ExternalIdentityProvider::GOOGLE, 'google-subject', $user->email, new DateTimeImmutable('2026-09-10T12:00:00Z'));
        $identities->save($identity);

        self::assertNull($users->byId($user->id)?->passwordHash);
        self::assertSame($user->id->value, $identities->byProviderSubject(ExternalIdentityProvider::GOOGLE, 'google-subject')?->userId->value);
        self::assertEquals(0, $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'identity_external_identities' AND column_name IN ('access_token', 'refresh_token')"));
    }

    public function test_database_prevents_reassigning_provider_subject(): void
    {
        $users = new DoctrineUsers($this->connection);
        $identities = new DoctrineExternalIdentities($this->connection);
        $first = new User(new UserId('019b1000-0000-7000-8000-000000000003'), new Email('first@example.test'), null, false, false, new DateTimeImmutable('2026-09-10T12:00:00Z'));
        $second = new User(new UserId('019b1000-0000-7000-8000-000000000004'), new Email('second@example.test'), null, false, false, new DateTimeImmutable('2026-09-10T12:00:00Z'));
        $users->save($first);
        $users->save($second);
        $identities->save(new ExternalIdentity('019b1000-0000-7000-8000-000000000005', $first->id, ExternalIdentityProvider::GOOGLE, 'same-subject', $first->email, new DateTimeImmutable('2026-09-10T12:00:00Z')));

        $this->expectException(UniqueConstraintViolationException::class);
        $identities->save(new ExternalIdentity('019b1000-0000-7000-8000-000000000006', $second->id, ExternalIdentityProvider::GOOGLE, 'same-subject', $second->email, new DateTimeImmutable('2026-09-10T12:00:00Z')));
    }
}
