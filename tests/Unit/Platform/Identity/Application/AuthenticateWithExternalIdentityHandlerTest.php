<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\Identity\Application;

use App\Platform\Identity\Application\Command\AuthenticateWithExternalIdentity;
use App\Platform\Identity\Application\Command\AuthenticateWithExternalIdentityHandler;
use App\Platform\Identity\Domain\Email;
use App\Platform\Identity\Domain\ExternalAuthenticationRejected;
use App\Platform\Identity\Domain\ExternalIdentities;
use App\Platform\Identity\Domain\ExternalIdentity;
use App\Platform\Identity\Domain\ExternalIdentityIdGenerator;
use App\Platform\Identity\Domain\ExternalIdentityProvider;
use App\Platform\Identity\Domain\IdentityTransaction;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserId;
use App\Platform\Identity\Domain\UserIdGenerator;
use App\Platform\Identity\Domain\Users;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class AuthenticateWithExternalIdentityHandlerTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryExternalIdentities $identities;
    private AuthenticateWithExternalIdentityHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->identities = new InMemoryExternalIdentities();
        $this->handler = new AuthenticateWithExternalIdentityHandler($this->users, $this->identities, new FixedUserIdGenerator(), new FixedExternalIdentityIdGenerator(), new ImmediateIdentityTransaction(), new FixedClock());
    }

    public function test_verified_google_identity_creates_passwordless_user_and_link(): void
    {
        $user = ($this->handler)(new AuthenticateWithExternalIdentity(ExternalIdentityProvider::GOOGLE, 'google-new', 'NEW@GMAIL.COM', true));

        self::assertNull($user->passwordHash);
        self::assertSame('new@gmail.com', $user->email->value);
        self::assertCount(1, $this->users->all);
        self::assertSame($user->id, $this->identities->all[0]->userId);
    }

    public function test_existing_password_user_is_linked_without_duplication(): void
    {
        $existing = $this->passwordUser('existing@gmail.com');
        $this->users->save($existing);

        $resolved = ($this->handler)(new AuthenticateWithExternalIdentity(ExternalIdentityProvider::GOOGLE, 'google-existing', 'existing@gmail.com', true));

        self::assertSame($existing, $resolved);
        self::assertCount(1, $this->users->all);
        self::assertCount(1, $this->identities->all);
    }

    public function test_linked_subject_resolves_same_user_without_relying_on_email_again(): void
    {
        $existing = $this->passwordUser('old@gmail.com');
        $this->users->save($existing);
        $this->identities->save(new ExternalIdentity('019b0000-0000-7000-8000-000000000003', $existing->id, ExternalIdentityProvider::GOOGLE, 'stable-subject', new Email('old@gmail.com'), new DateTimeImmutable('2026-09-10T12:00:00Z')));

        $resolved = ($this->handler)(new AuthenticateWithExternalIdentity(ExternalIdentityProvider::GOOGLE, 'stable-subject', 'changed@gmail.com', false));

        self::assertSame($existing, $resolved);
        self::assertCount(1, $this->users->all);
    }

    public function test_unverified_email_cannot_create_or_link_account(): void
    {
        $this->expectException(ExternalAuthenticationRejected::class);
        try {
            ($this->handler)(new AuthenticateWithExternalIdentity(ExternalIdentityProvider::GOOGLE, 'unverified', 'user@gmail.com', false));
        } finally {
            self::assertCount(0, $this->users->all);
            self::assertCount(0, $this->identities->all);
        }
    }

    public function test_second_google_subject_cannot_replace_existing_link(): void
    {
        $existing = $this->passwordUser('owner@gmail.com');
        $this->users->save($existing);
        $this->identities->save(new ExternalIdentity('019b0000-0000-7000-8000-000000000004', $existing->id, ExternalIdentityProvider::GOOGLE, 'first-subject', new Email('owner@gmail.com'), new DateTimeImmutable('2026-09-10T12:00:00Z')));

        $this->expectException(ExternalAuthenticationRejected::class);
        ($this->handler)(new AuthenticateWithExternalIdentity(ExternalIdentityProvider::GOOGLE, 'other-subject', 'owner@gmail.com', true));
    }

    private function passwordUser(string $email): User
    {
        return new User(new UserId('019b0000-0000-7000-8000-000000000001'), new Email($email), 'valid-password-hash', false, false, new DateTimeImmutable('2026-09-10T12:00:00Z'));
    }
}

final class InMemoryUsers implements Users
{
    /** @var list<User> */
    public array $all = [];

    public function byEmail(Email $email): ?User
    {
        foreach ($this->all as $user) {
            if ($user->email->value === $email->value) {
                return $user;
            }
        }

        return null;
    }

    public function byId(UserId $id): ?User
    {
        foreach ($this->all as $user) {
            if ($user->id->value === $id->value) {
                return $user;
            }
        }

        return null;
    }

    public function save(User $user): void
    {
        $this->all[] = $user;
    }

    public function markWorkerProfile(UserId $id): void
    {
    }
}

final class InMemoryExternalIdentities implements ExternalIdentities
{
    /** @var list<ExternalIdentity> */
    public array $all = [];

    public function byProviderSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity
    {
        foreach ($this->all as $identity) {
            if ($identity->provider === $provider && $identity->providerSubject === $subject) {
                return $identity;
            }
        }

        return null;
    }

    public function byUserAndProvider(UserId $userId, ExternalIdentityProvider $provider): ?ExternalIdentity
    {
        foreach ($this->all as $identity) {
            if ($identity->provider === $provider && $identity->userId->value === $userId->value) {
                return $identity;
            }
        }

        return null;
    }

    public function save(ExternalIdentity $identity): void
    {
        $this->all[] = $identity;
    }
}

final class FixedUserIdGenerator implements UserIdGenerator
{
    public function next(): UserId
    {
        return new UserId('019b0000-0000-7000-8000-000000000002');
    }
}

final class FixedExternalIdentityIdGenerator implements ExternalIdentityIdGenerator
{
    public function next(): string
    {
        return '019b0000-0000-7000-8000-000000000005';
    }
}

final class ImmediateIdentityTransaction implements IdentityTransaction
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

final class FixedClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-10T12:00:00Z');
    }

    public function sleep(float|int $seconds): void
    {
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
