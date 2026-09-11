<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

final readonly class ExternalCalendarConnection
{
    /** @param list<string> $grantedScopes */
    public function __construct(public string $id, public string $userId, public string $accountSubject, public string $accessToken, public ?string $refreshToken, public ?DateTimeImmutable $expiresAt, public array $grantedScopes, public ?DateTimeImmutable $revokedAt)
    {
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }
}
