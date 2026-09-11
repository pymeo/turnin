<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Http;

use App\Scheduling\Application\Command\ApplyScheduleDraft;
use App\Scheduling\Application\Command\ApplyManualShift;
use App\Scheduling\Application\Command\EnsureShiftPresets;
use App\Scheduling\Application\Command\ScheduleDraftApplied;
use App\Scheduling\Application\Query\CombinedRosterMonthView;
use App\Scheduling\Application\Query\DetectRosterPattern;
use App\Scheduling\Application\Query\GetCombinedRosterMonth;
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
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\RosterSource;
use InvalidArgumentException;
use LogicException;
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
        private RosterWorkspace $workspace,
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
            $assignments = $this->workspace->activeAssignments($workerId);
            $combined = 'all' === $request->query->getString('view') && \count($assignments) > 1;
            $selected = $combined ? null : $this->selectedAssignment($request, $workerId);
            if (null !== $selected && $selected->primary && $this->workspace->presetsFor($selected)->isEmpty()) {
                $this->commandBus->dispatch(new EnsureShiftPresets($workerId, $selected->assignmentId));
            }
            $month = $combined
                ? $this->roster->handled($this->queryBus, new GetCombinedRosterMonth($workerId, $request->query->getString('month') ?: null))
                : $this->month($request, $workerId, $selected?->assignmentId);
        } catch (Throwable $exception) {
            if (RosterRequest::rootCause($exception) instanceof RosterAccessDenied) {
                return new RedirectResponse('/onboarding');
            }

            throw $exception;
        }

        return new Response($this->twig->render('scheduling/calendar.html.twig', [
            'month' => $month,
            'weekdays' => GetRosterMonthHandler::weekdayInitials(),
            'presets' => null === $selected ? [] : $this->roster->handled($this->queryBus, new GetShiftPresets($workerId, false, $selected->assignmentId)),
            'patterns' => null === $selected ? [] : $this->roster->handled($this->queryBus, new GetRosterPatterns($workerId, $selected->assignmentId)),
            'nextShift' => null === $selected ? null : $this->roster->handled($this->queryBus, new GetNextShift($workerId, $selected->assignmentId)),
            'assignments' => $assignments,
            'selectedAssignment' => $selected,
            'combined' => $combined,
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
            $combined = 'all' === $request->query->getString('view');
            $result = $combined
                ? $this->roster->handled($this->queryBus, new GetCombinedRosterMonth($workerId, $request->query->getString('month') ?: null))
                : $this->month($request, $workerId, $this->selectedAssignment($request, $workerId)->assignmentId);
            if (!$result instanceof CombinedRosterMonthView && !$result instanceof RosterMonthView) {
                throw new LogicException('The roster month query returned an unexpected result.');
            }
            $month = $result;
        } catch (RosterAccessDenied|InvalidArgumentException $exception) {
            return new JsonResponse(['error' => RosterRequest::rootCause($exception)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'month' => $month->month,
            'title' => $month->title,
            'previousMonth' => $month->previousMonth,
            'nextMonth' => $month->nextMonth,
            'grid' => $this->twig->render($month instanceof CombinedRosterMonthView ? 'scheduling/_combined_month_grid.html.twig' : 'scheduling/_month_grid.html.twig', ['month' => $month]),
            'summary' => $this->twig->render($month instanceof CombinedRosterMonthView ? 'scheduling/_combined_month_summary.html.twig' : 'scheduling/_month_summary.html.twig', ['month' => $month]),
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
                $this->roster->assignmentId($request),
            ));

            return $preview instanceof ScheduleDraftPreview ? $this->renderPreview($preview) : [];
        });
    }

    #[Route('/app/calendar/manual', name: 'scheduling_calendar_manual', methods: ['POST'])]
    public function manual(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->roster->payload($request);
            $text = static fn (string $key): string => \is_string($payload[$key] ?? null) ? trim($payload[$key]) : '';
            $assignmentId = $this->roster->assignmentId($request) ?? '';
            $result = $this->roster->handled($this->commandBus, new ApplyManualShift($workerId, $assignmentId, $text('date'), $text('label'), $text('abbreviation'), $text('start'), $text('end'), $text('kind'), $text('colorKey')));

            return $result instanceof ScheduleDraftApplied ? ['writtenDays' => $result->writtenDays] : [];
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
                $this->roster->assignmentId($request),
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
                $this->roster->assignmentId($request),
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
        $detected = $this->roster->handled($this->queryBus, new DetectRosterPattern($workerId, $month, $this->roster->assignmentId($request)));

        return new JsonResponse(['ok' => true, 'result' => $detected]);
    }

    private function month(Request $request, string $workerId, ?string $assignmentId): RosterMonthView
    {
        $month = $this->roster->handled($this->queryBus, new GetRosterMonth($workerId, $request->query->getString('month') ?: null, $assignmentId));
        if (!$month instanceof RosterMonthView) {
            throw new InvalidArgumentException('No se pudo cargar el mes.');
        }

        return $month;
    }

    private function selectedAssignment(Request $request, string $workerId): AssignedWorker
    {
        $requested = $request->query->getString('assignment');
        if ('' !== $requested) {
            $worker = $this->workspace->requireAssignment($workerId, $requested);
            $request->getSession()->set('calendar_assignment', $worker->assignmentId);

            return $worker;
        }
        $remembered = $request->getSession()->get('calendar_assignment');
        if (\is_string($remembered)) {
            try {
                return $this->workspace->requireAssignment($workerId, $remembered);
            } catch (RosterAccessDenied) {
            }
        }

        return $this->workspace->requirePrimary($workerId);
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
