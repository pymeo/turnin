<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Http;

use App\Scheduling\Application\Command\ExportRosterCalendar;
use App\Scheduling\Application\Command\ImportExternalCalendar;
use App\Scheduling\Application\Command\RosterCalendarExported;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarProvider;
use App\Scheduling\Application\Query\ExportRosterIcs;
use App\Scheduling\Application\Query\ExternalCalendarImportPreview;
use App\Scheduling\Application\Query\PreviewExternalCalendarImport;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\WorkDate;
use DateTimeImmutable;
use InvalidArgumentException;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/app/calendar/integrations')]
final readonly class CalendarIntegrationController
{
    public function __construct(private RosterRequest $roster, private RosterWorkspace $workspace, private ExternalCalendarConnections $connections, private ExternalCalendarProvider $provider, private ClientRegistry $clients, private MessageBusInterface $queryBus, private MessageBusInterface $commandBus, private Environment $twig)
    {
    }

    #[Route('', name: 'scheduling_calendar_integrations', methods: ['GET'])]
    public function page(): Response
    {
        $userId = $this->roster->workerId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        $connection = $this->connections->activeFor($userId);

        return new Response($this->twig->render('scheduling/integrations.html.twig', ['connected' => null !== $connection, 'calendars' => null === $connection ? [] : $this->provider->listCalendars($userId), 'assignments' => $this->workspace->activeAssignments($userId)]));
    }

    #[Route('/google/connect', name: 'scheduling_google_calendar_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        if (null === $this->roster->workerId()) {
            return new RedirectResponse('/login');
        }
        $client = $this->googleClient();
        $export = 'export' === $request->query->getString('intent');
        $scopes = ['openid', 'email', 'https://www.googleapis.com/auth/calendar.calendarlist.readonly', $export ? 'https://www.googleapis.com/auth/calendar.events' : 'https://www.googleapis.com/auth/calendar.events.readonly'];

        return $client->redirect($scopes, ['access_type' => 'offline', 'include_granted_scopes' => 'true', 'prompt' => 'consent']);
    }

    #[Route('/google/callback', name: 'scheduling_google_calendar_callback', methods: ['GET'])]
    public function callback(): RedirectResponse
    {
        $userId = $this->roster->workerId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        $client = $this->googleClient();
        $token = $client->getAccessToken();
        if (!$token instanceof AccessToken) {
            throw new LogicException('Google Calendar has not returned a usable token.');
        }
        $owner = $client->fetchUserFromToken($token);
        if (!$owner instanceof GoogleUser || !\is_scalar($owner->getId())) {
            throw new LogicException('Google Calendar has not returned an account identity.');
        }
        $values = $token->getValues();
        $scope = $values['scope'] ?? '';
        $scopes = \is_string($scope) ? preg_split('/\s+/', trim($scope)) : [];
        $expires = $token->getExpires();
        $expiresAt = \is_int($expires) && $expires > 0 ? DateTimeImmutable::createFromFormat('U', (string) $expires) : null;
        $this->connections->connect($userId, (string) $owner->getId(), $token->getToken(), $token->getRefreshToken(), false === $expiresAt ? null : $expiresAt, false === $scopes ? [] : $scopes);

        return new RedirectResponse('/app/calendar/integrations');
    }

    #[Route('/google/disconnect', name: 'scheduling_google_calendar_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $userId): array {
            try {
                $this->provider->revoke($userId);
            } finally {
                $this->connections->disconnect($userId);
            }

            return [];
        });
    }

    #[Route('/google/import/preview', name: 'scheduling_google_calendar_import_preview', methods: ['POST'])]
    public function previewImport(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $userId) use ($request): array {
            $payload = $this->roster->payload($request);
            $preview = $this->roster->handled($this->queryBus, new PreviewExternalCalendarImport($userId, $this->required($payload, 'assignmentId'), $this->required($payload, 'calendarId'), $this->dateTime($payload, 'from'), $this->dateTime($payload, 'to')));
            if (!$preview instanceof ExternalCalendarImportPreview) {
                return [];
            }

            return ['recognized' => $preview->recognized, 'review' => $preview->review, 'ignored' => $preview->ignored, 'applyCount' => $preview->draft->applyCount, 'items' => array_map(static fn ($item): array => ['eventId' => $item->externalEventId, 'date' => (string) $item->entry->date, 'title' => $item->title, 'status' => $item->status, 'description' => $item->entry->describe()], $preview->items)];
        });
    }

    #[Route('/google/import', name: 'scheduling_google_calendar_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $userId) use ($request): array {
            $payload = $this->roster->payload($request);
            $ids = $payload['eventIds'] ?? [];
            $result = $this->roster->handled($this->commandBus, new ImportExternalCalendar($userId, $this->required($payload, 'assignmentId'), $this->required($payload, 'calendarId'), $this->dateTime($payload, 'from'), $this->dateTime($payload, 'to'), \is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [], ConflictPolicy::SKIP_EXISTING));

            return ['applied' => $result instanceof \App\Scheduling\Application\Command\ScheduleDraftApplied ? $result->writtenDays : 0];
        });
    }

    #[Route('/google/export', name: 'scheduling_google_calendar_export', methods: ['POST'])]
    public function export(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $userId) use ($request): array {
            $payload = $this->roster->payload($request);
            $result = $this->roster->handled($this->commandBus, new ExportRosterCalendar($userId, $this->required($payload, 'assignmentId'), $this->required($payload, 'calendarId'), WorkDate::fromString(substr($this->required($payload, 'from'), 0, 10)), WorkDate::fromString(substr($this->required($payload, 'to'), 0, 10))));

            return $result instanceof RosterCalendarExported ? ['created' => $result->created, 'updated' => $result->updated] : [];
        });
    }

    #[Route('/ics', name: 'scheduling_calendar_export_ics', methods: ['GET'])]
    public function ics(Request $request): Response
    {
        $userId = $this->roster->workerId();
        if (null === $userId) {
            return new RedirectResponse('/login');
        }
        $assignment = $request->query->getString('assignment');
        $content = $this->roster->handled($this->queryBus, new ExportRosterIcs($userId, $assignment, WorkDate::fromString($request->query->getString('from')), WorkDate::fromString($request->query->getString('to'))));

        return new Response(\is_string($content) ? $content : '', Response::HTTP_OK, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="turnin-turnos.ics"']);
    }

    private function googleClient(): GoogleClient
    {
        $client = $this->clients->getClient('google_calendar');
        if (!$client instanceof GoogleClient) {
            throw new LogicException('Google Calendar OAuth client is unavailable.');
        }

        return $client;
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new InvalidArgumentException('Falta un campo obligatorio.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $payload */
    private function dateTime(array $payload, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->required($payload, $key));
    }
}
