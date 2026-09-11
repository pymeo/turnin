<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Http;

use App\Scheduling\Application\Command\ApplyScheduleDraft;
use App\Scheduling\Application\Command\EnsureShiftPresets;
use App\Scheduling\Application\Command\ScheduleDraftApplied;
use App\Scheduling\Application\Query\DetectRosterPattern;
use App\Scheduling\Application\Query\GetNextShift;
use App\Scheduling\Application\Query\GetRosterMonth;
use App\Scheduling\Application\Query\GetRosterMonthHandler;
use App\Scheduling\Application\Query\GetRosterPatterns;
use App\Scheduling\Application\Query\GetShiftPresets;
use App\Scheduling\Application\Query\ParsedScheduleView;
use App\Scheduling\Application\Query\ParseScheduleText;
use App\Scheduling\Application\Query\PreviewScheduleDraft;
use App\Scheduling\Application\Query\RosterMonthView;
use App\Scheduling\Application\Query\ScheduleDraftPreview;
use App\Scheduling\Application\RosterAccessDenied;
use App\Scheduling\Domain\RosterSource;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;
use Twig\Environment;

/**
 * The calendar screen and the four operations behind it: look at a month,
 * preview a draft, apply it, and read back a dictated one.
 *
 * Thin on purpose. Every decision worth arguing about lives in Application or
 * Domain, which is where it can be unit-tested without a kernel.
 */
final readonly class CalendarController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private Environment $twig,
        private RosterRequest $roster,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/app/calendar', name: 'scheduling_calendar', methods: ['GET'])]
    public function calendar(Request $request): Response
    {
        $workerId = $this->roster->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        try {
            // First visit gets Mañana / Tarde / Noche rather than an empty
            // palette. Idempotent, so every later visit is a no-op.
            $this->commandBus->dispatch(new EnsureShiftPresets($workerId));
            $month = $this->month($request, $workerId);
        } catch (Throwable $exception) {
            if (RosterRequest::rootCause($exception) instanceof RosterAccessDenied) {
                return new RedirectResponse('/onboarding');
            }

            throw $exception;
        }

        return new Response($this->twig->render('scheduling/calendar.html.twig', [
            'month' => $month,
            'weekdays' => GetRosterMonthHandler::weekdayInitials(),
            'presets' => $this->roster->handled($this->queryBus, new GetShiftPresets($workerId)),
            'patterns' => $this->roster->handled($this->queryBus, new GetRosterPatterns($workerId)),
            'nextShift' => $this->roster->handled($this->queryBus, new GetNextShift($workerId)),
            'csrfToken' => $this->csrf->getToken(RosterRequest::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    /**
     * Paging between months swaps this fragment rather than re-rendering the
     * page, so there is exactly one template that knows how to draw a grid.
     */
    #[Route('/app/calendar/grid', name: 'scheduling_calendar_grid', methods: ['GET'])]
    public function grid(Request $request): Response
    {
        $workerId = $this->roster->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $month = $this->month($request, $workerId);
        } catch (RosterAccessDenied|InvalidArgumentException $exception) {
            return new JsonResponse(['error' => RosterRequest::rootCause($exception)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'month' => $month->month,
            'title' => $month->title,
            'previousMonth' => $month->previousMonth,
            'nextMonth' => $month->nextMonth,
            'grid' => $this->twig->render('scheduling/_month_grid.html.twig', ['month' => $month]),
            'summary' => $this->twig->render('scheduling/_month_summary.html.twig', ['month' => $month]),
        ]);
    }

    #[Route('/app/calendar/preview', name: 'scheduling_calendar_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $preview = $this->roster->handled($this->queryBus, new PreviewScheduleDraft(
                $workerId,
                $this->roster->instructionsFrom($request),
                $this->source($request),
                $this->roster->policyFrom($request),
            ));

            return $preview instanceof ScheduleDraftPreview ? $this->renderPreview($preview) : [];
        });
    }

    #[Route('/app/calendar/apply', name: 'scheduling_calendar_apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $applied = $this->roster->handled($this->commandBus, new ApplyScheduleDraft(
                $workerId,
                $this->roster->instructionsFrom($request),
                $this->source($request),
                $this->roster->policyFrom($request),
            ));

            if (!$applied instanceof ScheduleDraftApplied) {
                throw new InvalidArgumentException('No se pudo guardar el calendario.');
            }

            return [
                'writtenDays' => $applied->writtenDays,
                'clearedDays' => $applied->clearedDays,
                'skippedConflicts' => $applied->skippedConflicts,
                'month' => substr($applied->firstDate, 0, 7),
            ];
        });
    }

    /**
     * Voice and typing share this endpoint because voice *is* typing: the
     * browser transcribes, we receive text. No audio ever reaches Turnin.
     */
    #[Route('/app/calendar/interpret', name: 'scheduling_calendar_interpret', methods: ['POST'])]
    public function interpret(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->roster->payload($request);
            $parsed = $this->roster->handled($this->queryBus, new ParseScheduleText(
                $workerId,
                \is_string($payload['text'] ?? null) ? $payload['text'] : '',
                \is_string($payload['month'] ?? null) ? $payload['month'] : null,
                $this->source($request),
                $this->roster->policyFrom($request),
            ));

            if (!$parsed instanceof ParsedScheduleView) {
                throw new InvalidArgumentException('No hemos podido interpretar el texto.');
            }

            return [
                'preview' => $this->renderPreview($parsed->preview),
                'patternSlots' => $parsed->patternSlots,
                'patternSequence' => $parsed->patternSequence,
                'understoodNothing' => $parsed->understoodNothing,
            ];
        });
    }

    #[Route('/app/calendar/detect-pattern', name: 'scheduling_calendar_detect_pattern', methods: ['GET'])]
    public function detectPattern(Request $request): JsonResponse
    {
        $workerId = $this->roster->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }

        $month = $request->query->getString('month') ?: null;
        $detected = $this->roster->handled($this->queryBus, new DetectRosterPattern($workerId, $month));

        return new JsonResponse(['ok' => true, 'result' => $detected]);
    }

    private function month(Request $request, string $workerId): RosterMonthView
    {
        $month = $this->roster->handled($this->queryBus, new GetRosterMonth($workerId, $request->query->getString('month') ?: null));
        if (!$month instanceof RosterMonthView) {
            throw new InvalidArgumentException('No se pudo cargar el mes.');
        }

        return $month;
    }

    private function source(Request $request): RosterSource
    {
        $payload = $this->roster->payload($request);

        return RosterSource::tryFrom(\is_string($payload['source'] ?? null) ? $payload['source'] : '') ?? RosterSource::MANUAL;
    }

    /** @return array<string, mixed> */
    private function renderPreview(ScheduleDraftPreview $preview): array
    {
        return [
            'entries' => $preview->entries,
            'totalDays' => $preview->totalDays,
            'shiftCount' => $preview->shiftCount,
            'restCount' => $preview->restCount,
            'conflictCount' => $preview->conflictCount,
            'applyCount' => $preview->applyCount,
            'policy' => $preview->policy,
            'unrecognized' => $preview->unrecognized,
            'from' => $preview->from,
            'to' => $preview->to,
        ];
    }
}
