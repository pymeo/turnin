<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

interface ExternalCalendarConnections
{
    /** @param list<string> $scopes */
    public function connect(string $userId, string $accountSubject, ?string $accountEmail, string $accessToken, ?string $refreshToken, ?DateTimeImmutable $expiresAt, array $scopes): void;

    public function activeFor(string $userId): ?ExternalCalendarConnection;

    public function refreshAccessToken(string $userId, string $accessToken, ?DateTimeImmutable $expiresAt): void;

    public function requireReauthentication(string $userId): void;

    public function disconnect(string $userId): void;
}
