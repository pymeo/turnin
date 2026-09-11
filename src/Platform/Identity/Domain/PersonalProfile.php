<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class PersonalProfile
{
    private function __construct(private UserId $userId, private string $givenName, private string $familyName, private ?string $phoneEncrypted, private ?IdentityEvidence $identityEvidence, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt)
    {
    }

    public static function start(UserId $userId, string $givenName, string $familyName, DateTimeImmutable $now): self
    {
        $profile = new self($userId, '', '', null, null, $now, $now);
        $profile->rename($givenName, $familyName, $now);

        return $profile;
    }

    public function rename(string $givenName, string $familyName, DateTimeImmutable $now): void
    {
        $givenName = trim($givenName);
        $familyName = trim($familyName);
        if ('' === $givenName || '' === $familyName || mb_strlen($givenName) > 100 || mb_strlen($familyName) > 160) {
            throw new InvalidArgumentException('Indica tu nombre y apellidos.');
        }
        $this->givenName = $givenName;
        $this->familyName = $familyName;
        $this->updatedAt = $now;
    }

    public function protectPhone(string $phoneEncrypted, DateTimeImmutable $now): void
    {
        if ('' === $phoneEncrypted) {
            throw new InvalidArgumentException('El teléfono protegido no puede estar vacío.');
        }
        $this->phoneEncrypted = $phoneEncrypted;
        $this->identityEvidence = IdentityEvidence::PROVIDED;
        $this->updatedAt = $now;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function givenName(): string
    {
        return $this->givenName;
    }

    public function familyName(): string
    {
        return $this->familyName;
    }

    public function phoneEncrypted(): ?string
    {
        return $this->phoneEncrypted;
    }

    public function identityEvidence(): ?IdentityEvidence
    {
        return $this->identityEvidence;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
