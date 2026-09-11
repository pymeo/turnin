<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\ExternalCalendar;

use App\Scheduling\Application\ExternalCalendar\ExternalCalendar;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarEvent;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarEventDraft;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarPage;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarProvider;
use App\Scheduling\Application\ExternalCalendar\ExternalSyncTokenExpired;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Google REST adapter. No Google type crosses into Application. */
final readonly class GoogleCalendarProvider implements ExternalCalendarProvider
{
    public function __construct(private HttpClientInterface $httpClient, private ExternalCalendarConnections $connections, private string $googleOAuthClientId, private string $googleOAuthClientSecret)
    {
    }

    public function listCalendars(string $userId): array
    {
        $token = $this->accessToken($userId);
        $calendars = [];
        $pageToken = null;
        do {
            $query = null === $pageToken ? [] : ['pageToken' => $pageToken];
            $data = $this->request('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList', $token, ['query' => $query]);
            foreach ($this->items($data) as $row) {
                $id = $row['id'] ?? null;
                $name = $row['summary'] ?? null;
                if (\is_string($id) && \is_string($name)) {
                    $calendars[] = new ExternalCalendar($id, $name, \in_array($row['accessRole'] ?? null, ['owner', 'writer'], true));
                }
            }
            $pageToken = \is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : null;
        } while (null !== $pageToken);

        return $calendars;
    }

    public function listEvents(string $userId, string $calendarId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $syncToken = null): ExternalCalendarPage
    {
        $token = $this->accessToken($userId);
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;
        do {
            $query = ['singleEvents' => 'true', 'showDeleted' => 'true', 'maxResults' => 2500];
            if (null === $syncToken) {
                $query['timeMin'] = $from->format(\DATE_RFC3339);
                $query['timeMax'] = $to->format(\DATE_RFC3339);
                $query['orderBy'] = 'startTime';
            } else {
                $query['syncToken'] = $syncToken;
            }
            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }
            $response = $this->httpClient->request('GET', 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', ['auth_bearer' => $token, 'query' => $query]);
            if (410 === $response->getStatusCode()) {
                throw new ExternalSyncTokenExpired('Google ha invalidado el cursor de sincronización.');
            }
            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
            foreach ($this->items($data) as $row) {
                $event = $this->event($row);
                if (null !== $event) {
                    $events[] = $event;
                }
            }
            $pageToken = \is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : null;
            $nextSyncToken = \is_string($data['nextSyncToken'] ?? null) ? $data['nextSyncToken'] : $nextSyncToken;
        } while (null !== $pageToken);

        return new ExternalCalendarPage($events, $nextSyncToken);
    }

    public function createEvent(string $userId, string $calendarId, ExternalCalendarEventDraft $event): string
    {
        $data = $this->request('POST', 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', $this->accessToken($userId), ['json' => $this->eventPayload($event)]);
        $id = $data['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('Google Calendar no ha devuelto el identificador del evento creado.');
        }

        return $id;
    }

    public function updateEvent(string $userId, string $calendarId, string $eventId, ExternalCalendarEventDraft $event): void
    {
        $this->request('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), $this->accessToken($userId), ['json' => $this->eventPayload($event)]);
    }

    public function revoke(string $userId): void
    {
        $connection = $this->connections->activeFor($userId);
        if (null === $connection) {
            return;
        }
        $this->httpClient->request('POST', 'https://oauth2.googleapis.com/revoke', ['body' => ['token' => $connection->refreshToken ?? $connection->accessToken]])->getStatusCode();
    }

    private function accessToken(string $userId): string
    {
        $connection = $this->connections->activeFor($userId) ?? throw new RuntimeException('Google Calendar no está conectado.');
        if (null === $connection->expiresAt || $connection->expiresAt->getTimestamp() > time() + 60) {
            return $connection->accessToken;
        }
        if (null === $connection->refreshToken) {
            throw new RuntimeException('Google ha revocado el acceso. Vuelve a conectar Calendar.');
        }
        $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', ['body' => ['client_id' => $this->googleOAuthClientId, 'client_secret' => $this->googleOAuthClientSecret, 'refresh_token' => $connection->refreshToken, 'grant_type' => 'refresh_token']]);
        $data = $response->toArray(false);
        $token = $data['access_token'] ?? null;
        if (!\is_string($token)) {
            throw new RuntimeException('No hemos podido renovar el acceso a Google Calendar.');
        }
        $expiresIn = $data['expires_in'] ?? 3600;
        $expiresAt = new DateTimeImmutable('+'.(is_numeric($expiresIn) ? (int) $expiresIn : 3600).' seconds');
        $this->connections->refreshAccessToken($userId, $token, $expiresAt);

        return $token;
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, string $token, array $options = []): array
    {
        $options['auth_bearer'] = $token;
        $response = $this->httpClient->request($method, $url, $options);
        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('No hemos podido sincronizar Google Calendar. Tus turnos de Turnin siguen intactos.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function items(array $data): array
    {
        $items = $data['items'] ?? [];

        if (!\is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($item as $key => $value) {
                $row[(string) $key] = $value;
            }
            $result[] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function event(array $row): ?ExternalCalendarEvent
    {
        $id = $row['id'] ?? null;
        $start = \is_array($row['start'] ?? null) ? $row['start'] : [];
        $end = \is_array($row['end'] ?? null) ? $row['end'] : [];
        $startValue = $start['dateTime'] ?? $start['date'] ?? null;
        $endValue = $end['dateTime'] ?? $end['date'] ?? null;
        if (!\is_string($id) || !\is_string($startValue) || !\is_string($endValue)) {
            return null;
        }

        return new ExternalCalendarEvent($id, \is_string($row['summary'] ?? null) ? $row['summary'] : 'Sin título', new DateTimeImmutable($startValue), new DateTimeImmutable($endValue), isset($start['date']) && !isset($start['dateTime']), 'cancelled' === ($row['status'] ?? null), new DateTimeImmutable(\is_string($row['updated'] ?? null) ? $row['updated'] : $startValue));
    }

    /** @return array<string, mixed> */
    private function eventPayload(ExternalCalendarEventDraft $event): array
    {
        return ['summary' => $event->title, 'description' => $event->description, 'start' => ['dateTime' => $event->startsAt->format(\DATE_RFC3339)], 'end' => ['dateTime' => $event->endsAt->format(\DATE_RFC3339)], 'extendedProperties' => ['private' => $event->privateMetadata]];
    }
}
