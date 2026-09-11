<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Http;

use App\Scheduling\Domain\AuthenticatedWorkers;
use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\DraftInstruction;
use App\Scheduling\Domain\DraftIntent;
use App\Scheduling\Domain\WorkDate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

/**
 * The three things every calendar endpoint does before anything else: work out
 * who is asking, check the token, and turn the posted draft into instructions.
 *
 * Written once so that "the worker assignment comes from the session, never
 * from the request" cannot be forgotten in the fourth endpoint.
 */
final readonly class RosterRequest
{
    public const CSRF_TOKEN_ID = 'calendar';

    private const MAXIMUM_DRAFT_DAYS = 800;

    public function __construct(
        private TokenStorageInterface $tokens,
        private AuthenticatedWorkers $workers,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function workerId(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof UserInterface ? $this->workers->idForEmail($user->getUserIdentifier()) : null;
    }

    public function hasValidToken(Request $request): bool
    {
        return $this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-TOKEN', '')));
    }

    /**
     * The wire format is deliberately narrow: a date, an intent and preset ids.
     * Hours and labels are never accepted from the browser, so a segment can
     * only ever be a snapshot of a preset this worker owns.
     *
     * @return list<DraftInstruction>
     */
    public function instructionsFrom(Request $request): array
    {
        $payload = $this->payload($request);
        $entries = $payload['entries'] ?? null;
        if (!\is_array($entries)) {
            throw new InvalidArgumentException('No hemos recibido ningún día.');
        }
        if (\count($entries) > self::MAXIMUM_DRAFT_DAYS) {
            throw new InvalidArgumentException('Son demasiados días de una vez.');
        }

        $instructions = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $date = WorkDate::fromString(\is_string($entry['date'] ?? null) ? $entry['date'] : '');
            $intent = DraftIntent::tryFrom(\is_string($entry['intent'] ?? null) ? $entry['intent'] : '')
                ?? throw new InvalidArgumentException('No reconocemos esa acción sobre el día.');

            $presetIds = [];
            $raw = $entry['presetIds'] ?? [];
            if (\is_array($raw)) {
                foreach ($raw as $presetId) {
                    if (\is_string($presetId) && '' !== $presetId) {
                        $presetIds[] = $presetId;
                    }
                }
            }

            $instructions[] = new DraftInstruction($date, $intent, $presetIds);
        }

        return $instructions;
    }

    public function policyFrom(Request $request): ConflictPolicy
    {
        $payload = $this->payload($request);

        return ConflictPolicy::fromRequest(\is_string($payload['policy'] ?? null) ? $payload['policy'] : null);
    }

    /** @return array<string, mixed> */
    public function payload(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return $request->request->all();
        }

        $decoded = json_decode($content, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    public function handled(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    /**
     * Session, token, operation, JSON — the same four steps for every mutating
     * endpoint in this context, including the error shape the Stimulus
     * controllers read.
     *
     * @param callable(string): array<string, mixed> $operation
     */
    public function respond(Request $request, callable $operation): JsonResponse
    {
        $workerId = $this->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->hasValidToken($request)) {
            return new JsonResponse(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
        }

        try {
            return new JsonResponse(['ok' => true, 'result' => $operation($workerId)]);
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => self::rootCause($exception)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Messenger wraps whatever a handler threw in a HandlerFailedException whose
     * message reads "Handling \"App\\...\\Command\" failed: …". The part a
     * worker should see is the innermost one.
     */
    public static function rootCause(Throwable $exception): Throwable
    {
        while ($exception instanceof HandlerFailedException) {
            $previous = $exception->getPrevious();
            if (null === $previous) {
                break;
            }
            $exception = $previous;
        }

        return $exception;
    }
}
