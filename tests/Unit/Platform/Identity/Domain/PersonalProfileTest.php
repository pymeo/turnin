<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\Identity\Domain;

use App\Platform\Identity\Domain\IdentityEvidence;
use App\Platform\Identity\Domain\PersonalProfile;
use App\Platform\Identity\Domain\SpanishIdentityDocument;
use App\Platform\Identity\Domain\SpanishPhoneNumber;
use App\Platform\Identity\Domain\UserId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersonalProfileTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validDocuments(): iterable
    {
        yield 'DNI with separators' => ['12 345 678-z', '12345678Z'];
        yield 'NIE' => ['x-1234567-l', 'X1234567L'];
    }

    #[DataProvider('validDocuments')]
    public function test_spanish_identity_documents_are_validated_and_normalized(string $input, string $expected): void
    {
        self::assertSame($expected, new SpanishIdentityDocument($input)->normalized);
    }

    public function test_an_identity_document_with_the_wrong_control_letter_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpanishIdentityDocument('12345678A');
    }

    /** @return iterable<string, array{string}> */
    public static function validPhones(): iterable
    {
        yield 'national mobile' => ['600 123 123'];
        yield 'international mobile' => ['+34 600 123 123'];
        yield 'international landline' => ['0034 910 123 123'];
    }

    #[DataProvider('validPhones')]
    public function test_spanish_phones_are_normalized_to_e164(string $input): void
    {
        self::assertMatchesRegularExpression('/^\+34[6-9]\d{8}$/', new SpanishPhoneNumber($input)->e164);
    }

    public function test_a_non_spanish_phone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpanishPhoneNumber('+351912345678');
    }

    public function test_profile_name_and_protected_identity_follow_explicit_timestamps(): void
    {
        $created = new DateTimeImmutable('2026-09-11T08:00:00Z');
        $renamed = new DateTimeImmutable('2026-09-11T09:00:00Z');
        $protected = new DateTimeImmutable('2026-09-11T10:00:00Z');
        $profile = PersonalProfile::start(new UserId('019b2000-0000-7000-8000-000000000001'), ' Ana ', ' García ', $created);

        $profile->rename('María', 'García López', $renamed);
        $profile->protectPhone('protected-value', $protected);

        self::assertSame('María', $profile->givenName());
        self::assertSame('García López', $profile->familyName());
        self::assertSame(IdentityEvidence::PROVIDED, $profile->identityEvidence());
        self::assertSame($created, $profile->createdAt());
        self::assertSame($protected, $profile->updatedAt());
    }

    public function test_a_profile_requires_both_name_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PersonalProfile::start(new UserId('019b2000-0000-7000-8000-000000000002'), 'Ana', ' ', new DateTimeImmutable('2026-09-11T08:00:00Z'));
    }
}
