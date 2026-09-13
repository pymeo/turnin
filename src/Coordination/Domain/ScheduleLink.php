<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class ScheduleLink
{
    private function __construct(private readonly string $id, private readonly string $firstUserId, private readonly string $secondUserId, private readonly DateTimeImmutable $createdAt, private ?DateTimeImmutable $revokedAt)
    {
        if ('' === trim($id) || $firstUserId >= $secondUserId) {
            throw new InvalidArgumentException('A schedule link needs two distinct canonical user identities.');
        }
    }

    public static function create(string $id, string $userA, string $userB, DateTimeImmutable $now): self
    {
        [$first, $second] = self::pair($userA, $userB);

        return new self($id, $first, $second, $now, null);
    }

    public static function restore(string $id, string $firstUserId, string $secondUserId, DateTimeImmutable $createdAt, ?DateTimeImmutable $revokedAt): self
    {
        return new self($id, $firstUserId, $secondUserId, $createdAt, $revokedAt);
    }

    public function includes(string $userId): bool
    {
        return $userId === $this->firstUserId || $userId === $this->secondUserId;
    }

    public function other(string $userId): string
    {
        if (!$this->includes($userId)) {
            throw new InvalidArgumentException('No formas parte de este calendario compartido.');
        }

        return $userId === $this->firstUserId ? $this->secondUserId : $this->firstUserId;
    }

    public function revoke(string $actorId, DateTimeImmutable $now): void
    {
        if (!$this->includes($actorId)) {
            throw new InvalidArgumentException('No puedes desvincular calendarios ajenos.');
        }
        $this->revokedAt = $now;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firstUserId(): string
    {
        return $this->firstUserId;
    }

    public function secondUserId(): string
    {
        return $this->secondUserId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /** @return array{string, string} */
    public static function pair(string $userA, string $userB): array
    {
        if ('' === trim($userA) || '' === trim($userB) || $userA === $userB) {
            throw new InvalidArgumentException('A schedule link needs two different users.');
        }

        return $userA < $userB ? [$userA, $userB] : [$userB, $userA];
    }
}
