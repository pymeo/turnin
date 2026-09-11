<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Http;

use App\Scheduling\Application\Command\ApplyRosterPattern;
use App\Scheduling\Application\Command\CreateRosterPattern;
use App\Scheduling\Application\Command\RenameRosterPattern;
use App\Scheduling\Application\Command\ScheduleDraftApplied;
use App\Scheduling\Application\Query\PreviewRosterPattern;
use App\Scheduling\Application\Query\ScheduleDraftPreview;
use App\Scheduling\Domain\ConflictPolicy;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rotations: create one, see what it would do, apply it.
 *
 * Preview and apply are separate calls on purpose. Three months of rotation is
 * around a hundred days, and a hundred days is exactly the amount of work
 * nobody wants to discover was overwritten.
 */
final readonly class RosterPatternController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private RosterRequest $roster,
    ) {
    }

    #[Route('/app/calendar/patrones', name: 'scheduling_patterns_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request): array {
            $payload = $this->roster->payload($request);
            $slots = [];
            $raw = $payload['slots'] ?? [];
            if (!\is_array($raw) || [] === $raw) {
                throw new InvalidArgumentException('Añade al menos un día al patrón.');
            }
            foreach ($raw as $slot) {
                $slots[] = \is_string($slot) && '' !== $slot ? $slot : null;
            }

            $name = \is_string($payload['name'] ?? null) && '' !== trim($payload['name']) ? trim($payload['name']) : null;
            $id = $this->roster->handled($this->commandBus, new CreateRosterPattern($workerId, $name, $slots));

            return ['patternId' => \is_string($id) ? $id : null];
        });
    }

    #[Route('/app/calendar/patrones/{patternId}/nombre', name: 'scheduling_patterns_rename', methods: ['POST'])]
    public function rename(Request $request, string $patternId): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request, $patternId): array {
            $payload = $this->roster->payload($request);
            $this->commandBus->dispatch(new RenameRosterPattern($workerId, $patternId, \is_string($payload['name'] ?? null) ? $payload['name'] : ''));

            return [];
        });
    }

    #[Route('/app/calendar/patrones/{patternId}/previsualizar', name: 'scheduling_patterns_preview', methods: ['POST'])]
    public function preview(Request $request, string $patternId): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request, $patternId): array {
            $payload = $this->roster->payload($request);
            $preview = $this->roster->handled($this->queryBus, new PreviewRosterPattern(
                $workerId,
                $patternId,
                $this->date($payload, 'from'),
                $this->date($payload, 'to'),
                ConflictPolicy::fromRequest(\is_string($payload['policy'] ?? null) ? $payload['policy'] : null),
            ));

            if (!$preview instanceof ScheduleDraftPreview) {
                throw new InvalidArgumentException('No se pudo previsualizar el patrón.');
            }

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
        });
    }

    #[Route('/app/calendar/patrones/{patternId}/aplicar', name: 'scheduling_patterns_apply', methods: ['POST'])]
    public function apply(Request $request, string $patternId): JsonResponse
    {
        return $this->roster->respond($request, function (string $workerId) use ($request, $patternId): array {
            $payload = $this->roster->payload($request);
            $applied = $this->roster->handled($this->commandBus, new ApplyRosterPattern(
                $workerId,
                $patternId,
                $this->date($payload, 'from'),
                $this->date($payload, 'to'),
                ConflictPolicy::fromRequest(\is_string($payload['policy'] ?? null) ? $payload['policy'] : null),
            ));

            if (!$applied instanceof ScheduleDraftApplied) {
                throw new InvalidArgumentException('No se pudo aplicar el patrón.');
            }

            return [
                'writtenDays' => $applied->writtenDays,
                'skippedConflicts' => $applied->skippedConflicts,
                'month' => substr($applied->firstDate, 0, 7),
            ];
        });
    }

    /** @param array<string, mixed> $payload */
    private function date(array $payload, string $key): string
    {
        return \is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }
}
