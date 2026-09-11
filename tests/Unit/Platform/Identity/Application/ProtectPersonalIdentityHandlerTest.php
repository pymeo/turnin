<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\Identity\Application;

use App\Platform\Identity\Application\Command\ProtectPersonalIdentity;
use App\Platform\Identity\Application\Command\ProtectPersonalIdentityHandler;
use App\Platform\Identity\Domain\IdentityTransaction;
use App\Platform\Identity\Domain\PersonalDataCipher;
use App\Platform\Identity\Domain\PersonalIdentifierFingerprinter;
use App\Platform\Identity\Domain\PersonalProfile;
use App\Platform\Identity\Domain\PersonalProfiles;
use App\Platform\Identity\Domain\UsageIdentities;
use App\Platform\Identity\Domain\UsageIdentity;
use App\Platform\Identity\Domain\UsageIdentityIdGenerator;
use App\Platform\Identity\Domain\UserId;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class ProtectPersonalIdentityHandlerTest extends TestCase
{
    public function test_it_normalizes_fingerprints_and_encrypts_the_phone(): void
    {
        $profile = $this->profile();
        $profiles = new ProtectIdentityProfiles($profile);
        $identities = new ProtectIdentityUsageIdentities();
        $handler = $this->handler($profiles, $identities);

        $handler(new ProtectPersonalIdentity($profile->userId()->value, '12 345 678-z', '600 123 123'));

        self::assertNotNull($identities->identity);
        self::assertSame(hash('sha256', '12345678Z'), $identities->identity->identityDocumentFingerprint);
        self::assertSame(hash('sha256', '+34600123123'), $identities->identity->phoneFingerprint);
        self::assertSame('encrypted:+34600123123', $profile->phoneEncrypted());
    }

    public function test_equivalent_document_and_phone_spellings_produce_identical_fingerprints(): void
    {
        $fingerprints = [];
        foreach ([
            ['12345678Z', '600123123'],
            ['12345678-z', '+34 600 123 123'],
            ['12 345 678 Z', '0034 600123123'],
        ] as [$document, $phone]) {
            $profile = $this->profile();
            $identities = new ProtectIdentityUsageIdentities();
            $this->handler(new ProtectIdentityProfiles($profile), $identities)(new ProtectPersonalIdentity($profile->userId()->value, $document, $phone));
            self::assertNotNull($identities->identity);
            $fingerprints[] = [$identities->identity->identityDocumentFingerprint, $identities->identity->phoneFingerprint];
        }

        self::assertSame($fingerprints[0], $fingerprints[1]);
        self::assertSame($fingerprints[0], $fingerprints[2]);
    }

    public function test_identifying_values_cannot_be_silently_changed(): void
    {
        $profile = $this->profile();
        $profiles = new ProtectIdentityProfiles($profile);
        $identities = new ProtectIdentityUsageIdentities();
        $handler = $this->handler($profiles, $identities);
        $handler(new ProtectPersonalIdentity($profile->userId()->value, '12345678Z', '600123123'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contacta con soporte');
        $handler(new ProtectPersonalIdentity($profile->userId()->value, '12345678Z', '611123123'));
    }

    public function test_name_must_exist_before_identity_is_protected(): void
    {
        $profiles = new ProtectIdentityProfiles(null);
        $handler = $this->handler($profiles, new ProtectIdentityUsageIdentities());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Completa primero tu nombre');
        $handler(new ProtectPersonalIdentity('019b2000-0000-7000-8000-000000000001', '12345678Z', '600123123'));
    }

    private function profile(): PersonalProfile
    {
        return PersonalProfile::start(new UserId('019b2000-0000-7000-8000-000000000001'), 'Ana', 'García', new DateTimeImmutable('2026-09-11T08:00:00Z'));
    }

    private function handler(ProtectIdentityProfiles $profiles, ProtectIdentityUsageIdentities $identities): ProtectPersonalIdentityHandler
    {
        return new ProtectPersonalIdentityHandler($profiles, $identities, new HashFingerprinter(), new PrefixCipher(), new ProtectIdentityIdGenerator(), new ImmediateProtectIdentityTransaction(), new ProtectIdentityClock());
    }
}

final class ProtectIdentityProfiles implements PersonalProfiles
{
    public function __construct(public ?PersonalProfile $profile)
    {
    }

    public function byUserId(UserId $userId): ?PersonalProfile
    {
        return $this->profile;
    }

    public function save(PersonalProfile $profile): void
    {
        $this->profile = $profile;
    }
}

final class ProtectIdentityUsageIdentities implements UsageIdentities
{
    public ?UsageIdentity $identity = null;

    public function byUserId(UserId $userId): ?UsageIdentity
    {
        return $this->identity;
    }

    public function save(UsageIdentity $identity): void
    {
        $this->identity = $identity;
    }
}

final readonly class HashFingerprinter implements PersonalIdentifierFingerprinter
{
    public function fingerprint(string $normalizedValue): string
    {
        return hash('sha256', $normalizedValue);
    }
}

final readonly class PrefixCipher implements PersonalDataCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'encrypted:'.$plaintext;
    }

    public function decrypt(string $ciphertext): string
    {
        return substr($ciphertext, 10);
    }
}

final readonly class ProtectIdentityIdGenerator implements UsageIdentityIdGenerator
{
    public function next(): string
    {
        return '019b2000-0000-7000-8000-000000000002';
    }
}

final readonly class ImmediateProtectIdentityTransaction implements IdentityTransaction
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

final readonly class ProtectIdentityClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11T09:00:00Z');
    }

    public function sleep(float|int $seconds): void
    {
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
