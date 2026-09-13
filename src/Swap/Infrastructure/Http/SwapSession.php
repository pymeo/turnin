<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Http;

use App\Swap\Domain\AuthenticatedWorkers;
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
 * Session, token and JSON shape for every exchange endpoint, written once.
 *
 * The worker id always comes from the signed-in session. Nothing in this
 * context accepts a worker, an assignment or a pool as an identity claim from
 * the browser — they are re-resolved in SwapWorkspace before anything happens.
 */
final readonly class SwapSession
{
    public const CSRF_TOKEN_ID = 'changes';

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

    public function token(): string
    {
        return $this->csrf->getToken(self::CSRF_TOKEN_ID)->getValue();
    }

    public function isValid(Request $request): bool
    {
        $value = $request->headers->get('X-CSRF-TOKEN', $request->request->getString('_token'));

        return $this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $value));
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

    /** @param array<string, mixed> $payload
     * @return list<string>
     */
    public function strings(array $payload, string $key): array
    {
        $raw = $payload[$key] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $payload */
    public function text(array $payload, string $key): string
    {
        return \is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }

    public function handled(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    /** @param callable(string): array<string, mixed> $operation */
    public function respond(Request $request, callable $operation): JsonResponse
    {
        $workerId = $this->workerId();
        if (null === $workerId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isValid($request)) {
            return new JsonResponse(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
        }

        try {
            return new JsonResponse(['ok' => true, 'result' => $operation($workerId)]);
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => self::rootCause($exception)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** Messenger wraps handler failures; the worker should read the inner message. */
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
