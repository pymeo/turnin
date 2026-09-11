<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Http;

use App\Scheduling\Application\Command\CopyShiftPresets;
use App\Scheduling\Application\Command\DeactivateShiftPreset;
use App\Scheduling\Application\Command\EnsureShiftPresets;
use App\Scheduling\Application\Command\ReorderShiftPresets;
use App\Scheduling\Application\Command\SaveShiftPreset;
use App\Scheduling\Application\Query\GetShiftPresets;
use App\Scheduling\Application\RosterAccessDenied;
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
 * "Gestionar mis turnos": the screen behind the ⋯ menu. Deliberately not on the
 * calendar itself — a worker opens the calendar to see their month, not to
 * configure it.
 */
final readonly class ShiftPresetController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private Environment $twig,
        private RosterRequest $roster,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/app/calendar/turnos', name: 'scheduling_shift_presets', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $workerId = $this->roster->workerId();
        if (null === $workerId) {
            return new RedirectResponse('/login');
        }

        try {
            $assignmentId = $this->roster->assignmentId($request);
            $presets = $this->roster->handled($this->queryBus, new GetShiftPresets($workerId, true, $assignmentId));
        } catch (Throwable $exception) {
            if (RosterRequest::rootCause($exception) instanceof RosterAccessDenied) {
                return new RedirectResponse('/onboarding');
            }

            throw $exception;
        }

        return new Response($this->twig->render('scheduling/shift_presets.html.twig', [
            'presets' => $presets,
            'csrfToken' => $this->csrf->getToken(RosterRequest::CSRF_TOKEN_ID)->getValue(),
            'assignmentId' => $assignmentId,
        ]));
    }

    #[Route('/app/calendar/turnos/guardar', name: 'scheduling_shift_presets_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->roster->payload($request);

            $id = $this->roster->handled($this->commandBus, new SaveShiftPreset(
                $workerId,
                $this->optional($payload, 'presetId'),
                $this->required($payload, 'name'),
                $this->required($payload, 'abbreviation'),
                $this->required($payload, 'start'),
                $this->required($payload, 'end'),
                $this->required($payload, 'kind'),
                $this->aliases($payload),
                $this->required($payload, 'colorKey'),
                $this->roster->assignmentId($request),
            ));

            return ['presetId' => \is_string($id) ? $id : null];
        });
    }

    #[Route('/app/calendar/turnos/inicializar', name: 'scheduling_shift_presets_seed', methods: ['POST'])]
    public function seed(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $assignmentId = $this->roster->assignmentId($request);
            $this->commandBus->dispatch(new EnsureShiftPresets($workerId, $assignmentId));

            return [];
        });
    }

    #[Route('/app/calendar/turnos/copiar', name: 'scheduling_shift_presets_copy', methods: ['POST'])]
    public function copy(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->roster->payload($request);
            $target = $this->roster->assignmentId($request) ?? '';
            $this->commandBus->dispatch(new CopyShiftPresets($workerId, $this->required($payload, 'sourceAssignmentId'), $target));

            return [];
        });
    }

    #[Route('/app/calendar/turnos/{presetId}/retirar', name: 'scheduling_shift_presets_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, string $presetId): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request, $presetId): array {
            $this->commandBus->dispatch(new DeactivateShiftPreset($workerId, $presetId, $this->roster->assignmentId($request)));

            return [];
        });
    }

    #[Route('/app/calendar/turnos/orden', name: 'scheduling_shift_presets_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->roster->payload($request);
            $ids = [];
            $raw = $payload['presetIds'] ?? [];
            if (\is_array($raw)) {
                foreach ($raw as $id) {
                    if (\is_string($id) && '' !== $id) {
                        $ids[] = $id;
                    }
                }
            }
            $this->commandBus->dispatch(new ReorderShiftPresets($workerId, $ids, $this->roster->assignmentId($request)));

            return [];
        });
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key): string
    {
        return \is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }

    /** @param array<string, mixed> $payload */
    private function optional(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function aliases(array $payload): array
    {
        $raw = $payload['aliases'] ?? [];
        if (\is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!\is_array($raw)) {
            return [];
        }

        $aliases = [];
        foreach ($raw as $alias) {
            if (\is_string($alias) && '' !== trim($alias)) {
                $aliases[] = trim($alias);
            }
        }

        return $aliases;
    }
}
