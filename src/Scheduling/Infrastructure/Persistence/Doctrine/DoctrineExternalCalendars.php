<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Persistence\Doctrine;

use App\Scheduling\Application\ExternalCalendar\CalendarMappingDirection;
use App\Scheduling\Application\ExternalCalendar\CalendarTokenCipher;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnection;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarMappings;
use App\Scheduling\Application\ExternalCalendar\ExternalRosterEvents;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class DoctrineExternalCalendars implements ExternalCalendarConnections, ExternalCalendarMappings, ExternalRosterEvents
{
    public function __construct(private Connection $connection, private CalendarTokenCipher $cipher)
    {
    }

    public function connect(string $userId, string $accountSubject, ?string $accountEmail, string $accessToken, ?string $refreshToken, ?DateTimeImmutable $expiresAt, array $scopes): void
    {
        $id = 'google:'.$userId;
        $existing = $this->connection->fetchAssociative('SELECT encrypted_refresh_token, granted_scopes FROM scheduling_external_calendar_connections WHERE id = :id', ['id' => $id]);
        $existingRefresh = false === $existing ? null : ($existing['encrypted_refresh_token'] ?? null);
        $encryptedRefresh = null !== $refreshToken ? $this->cipher->encrypt($refreshToken) : (\is_string($existingRefresh) ? $existingRefresh : null);
        $existingScopes = false === $existing ? [] : json_decode($this->text($existing['granted_scopes'] ?? null), true);
        $mergedScopes = array_values(array_unique([...array_filter(\is_array($existingScopes) ? $existingScopes : [], 'is_string'), ...$scopes]));
        $this->connection->executeStatement(
            'INSERT INTO scheduling_external_calendar_connections (id, user_id, provider, account_subject, account_email, encrypted_access_token, encrypted_refresh_token, expires_at, granted_scopes, connected_at, reauthentication_required_at, revoked_at) VALUES (:id, :user, :provider, :subject, :email, :access, :refresh, :expires, :scopes, NOW(), NULL, NULL) ON CONFLICT (id) DO UPDATE SET account_subject = EXCLUDED.account_subject, account_email = EXCLUDED.account_email, encrypted_access_token = EXCLUDED.encrypted_access_token, encrypted_refresh_token = EXCLUDED.encrypted_refresh_token, expires_at = EXCLUDED.expires_at, granted_scopes = EXCLUDED.granted_scopes, connected_at = NOW(), reauthentication_required_at = NULL, revoked_at = NULL',
            ['id' => $id, 'user' => $userId, 'provider' => 'google', 'subject' => $accountSubject, 'email' => $accountEmail, 'access' => $this->cipher->encrypt($accessToken), 'refresh' => $encryptedRefresh, 'expires' => $expiresAt, 'scopes' => json_encode($mergedScopes, \JSON_THROW_ON_ERROR)],
            ['expires' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function activeFor(string $userId): ?ExternalCalendarConnection
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM scheduling_external_calendar_connections WHERE user_id = :user AND provider = :provider AND revoked_at IS NULL', ['user' => $userId, 'provider' => 'google']);
        if (false === $row) {
            return null;
        }
        $scopes = json_decode($this->text($row['granted_scopes'] ?? null), true, 512, \JSON_THROW_ON_ERROR);

        return new ExternalCalendarConnection($this->text($row['id'] ?? null), $this->text($row['user_id'] ?? null), $this->text($row['account_subject'] ?? null), $this->nullableText($row['account_email'] ?? null), $this->cipher->decrypt($this->text($row['encrypted_access_token'] ?? null)), null === ($row['encrypted_refresh_token'] ?? null) ? null : $this->cipher->decrypt($this->text($row['encrypted_refresh_token'])), null === ($row['expires_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['expires_at'])), \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [], null === ($row['reauthentication_required_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['reauthentication_required_at'])), null);
    }

    public function refreshAccessToken(string $userId, string $accessToken, ?DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement('UPDATE scheduling_external_calendar_connections SET encrypted_access_token = :token, expires_at = :expires WHERE user_id = :user AND provider = :provider AND revoked_at IS NULL', ['token' => $this->cipher->encrypt($accessToken), 'expires' => $expiresAt, 'user' => $userId, 'provider' => 'google'], ['expires' => Types::DATETIMETZ_IMMUTABLE]);
    }

    public function requireReauthentication(string $userId): void
    {
        $this->connection->executeStatement('UPDATE scheduling_external_calendar_connections SET reauthentication_required_at = NOW() WHERE user_id = :user AND provider = :provider AND revoked_at IS NULL', ['user' => $userId, 'provider' => 'google']);
    }

    public function disconnect(string $userId): void
    {
        $this->connection->transactional(function () use ($userId): void {
            $id = 'google:'.$userId;
            $this->connection->executeStatement('UPDATE scheduling_external_calendar_connections SET encrypted_access_token = :blank, encrypted_refresh_token = NULL, revoked_at = NOW() WHERE id = :id', ['blank' => $this->cipher->encrypt('revoked'), 'id' => $id]);
            $this->connection->executeStatement('UPDATE scheduling_external_calendar_mappings SET active = FALSE WHERE connection_id = :id', ['id' => $id]);
        });
    }

    public function save(string $connectionId, string $externalCalendarId, string $workerAssignmentId, CalendarMappingDirection $direction, ?string $syncToken = null): void
    {
        $this->connection->executeStatement('INSERT INTO scheduling_external_calendar_mappings (connection_id, external_calendar_id, worker_assignment_id, direction, active, sync_token) VALUES (:connection, :calendar, :assignment, :direction, TRUE, :token) ON CONFLICT (connection_id, external_calendar_id, worker_assignment_id, direction) DO UPDATE SET active = TRUE, sync_token = COALESCE(EXCLUDED.sync_token, scheduling_external_calendar_mappings.sync_token)', ['connection' => $connectionId, 'calendar' => $externalCalendarId, 'assignment' => $workerAssignmentId, 'direction' => $direction->value, 'token' => $syncToken]);
    }

    public function syncToken(string $connectionId, string $externalCalendarId, string $workerAssignmentId): ?string
    {
        $token = $this->connection->fetchOne('SELECT sync_token FROM scheduling_external_calendar_mappings WHERE connection_id = :connection AND external_calendar_id = :calendar AND worker_assignment_id = :assignment AND direction = :direction AND active = TRUE', ['connection' => $connectionId, 'calendar' => $externalCalendarId, 'assignment' => $workerAssignmentId, 'direction' => CalendarMappingDirection::IMPORT->value]);

        return \is_string($token) && '' !== $token ? $token : null;
    }

    public function clearSyncToken(string $connectionId, string $externalCalendarId, string $workerAssignmentId): void
    {
        $this->connection->executeStatement('UPDATE scheduling_external_calendar_mappings SET sync_token = NULL WHERE connection_id = :connection AND external_calendar_id = :calendar AND worker_assignment_id = :assignment', ['connection' => $connectionId, 'calendar' => $externalCalendarId, 'assignment' => $workerAssignmentId]);
    }

    public function imported(string $connectionId, string $calendarId, string $eventId, string $assignmentId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM scheduling_external_roster_events WHERE connection_id = :connection AND external_calendar_id = :calendar AND external_event_id = :event AND worker_assignment_id = :assignment AND direction = :direction', ['connection' => $connectionId, 'calendar' => $calendarId, 'event' => $eventId, 'assignment' => $assignmentId, 'direction' => CalendarMappingDirection::IMPORT->value]);
    }

    public function externalEventIdForSegment(string $connectionId, string $calendarId, string $segmentId): ?string
    {
        $id = $this->connection->fetchOne('SELECT external_event_id FROM scheduling_external_roster_events WHERE connection_id = :connection AND external_calendar_id = :calendar AND segment_id = :segment AND direction = :direction', ['connection' => $connectionId, 'calendar' => $calendarId, 'segment' => $segmentId, 'direction' => CalendarMappingDirection::EXPORT->value]);

        return \is_string($id) ? $id : null;
    }

    public function recordImport(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId, DateTimeImmutable $externalUpdatedAt): void
    {
        $this->record($connectionId, $calendarId, $eventId, $assignmentId, $rosterDayId, $segmentId, CalendarMappingDirection::IMPORT, $externalUpdatedAt);
    }

    public function recordExport(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId): void
    {
        $this->record($connectionId, $calendarId, $eventId, $assignmentId, $rosterDayId, $segmentId, CalendarMappingDirection::EXPORT, null);
    }

    private function record(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId, CalendarMappingDirection $direction, ?DateTimeImmutable $updatedAt): void
    {
        $this->connection->executeStatement('INSERT INTO scheduling_external_roster_events (connection_id, external_calendar_id, external_event_id, worker_assignment_id, roster_day_id, segment_id, direction, external_updated_at) VALUES (:connection, :calendar, :event, :assignment, :day, :segment, :direction, :updated) ON CONFLICT (connection_id, external_calendar_id, external_event_id, worker_assignment_id, direction) DO UPDATE SET roster_day_id = EXCLUDED.roster_day_id, segment_id = EXCLUDED.segment_id, external_updated_at = EXCLUDED.external_updated_at', ['connection' => $connectionId, 'calendar' => $calendarId, 'event' => $eventId, 'assignment' => $assignmentId, 'day' => $rosterDayId, 'segment' => $segmentId, 'direction' => $direction->value, 'updated' => $updatedAt], ['updated' => Types::DATETIMETZ_IMMUTABLE]);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function nullableText(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
