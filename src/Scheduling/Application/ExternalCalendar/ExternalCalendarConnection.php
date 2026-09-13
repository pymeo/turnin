<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

final readonly class ExternalCalendarConnection
{
    public const CALENDAR_LIST_READ = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly';
    public const EVENTS_READ = 'https://www.googleapis.com/auth/calendar.events.readonly';
    public const EVENTS_WRITE = 'https://www.googleapis.com/auth/calendar.events';

    /** @param list<string> $grantedScopes */
    public function __construct(public string $id, public string $userId, public string $accountSubject, public ?string $accountEmail, public string $accessToken, public ?string $refreshToken, public ?DateTimeImmutable $expiresAt, public array $grantedScopes, public ?DateTimeImmutable $reauthenticationRequiredAt, public ?DateTimeImmutable $revokedAt)
    {
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function requiresReauthentication(): bool
    {
        return null !== $this->reauthenticationRequiredAt;
    }

    public function canReadCalendars(): bool
    {
        return $this->hasScope(self::CALENDAR_LIST_READ) || $this->hasScope('https://www.googleapis.com/auth/calendar.readonly') || $this->hasScope('https://www.googleapis.com/auth/calendar');
    }

    public function canReadEvents(): bool
    {
        return $this->hasScope(self::EVENTS_READ) || $this->canWriteEvents() || $this->hasScope('https://www.googleapis.com/auth/calendar.readonly');
    }

    public function canWriteEvents(): bool
    {
        return $this->hasScope(self::EVENTS_WRITE) || $this->hasScope('https://www.googleapis.com/auth/calendar');
    }

    private function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->grantedScopes, true);
    }
}
