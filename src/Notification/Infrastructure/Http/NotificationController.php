<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Http;

use App\Notification\Application\Command\MarkAllNotificationsRead;
use App\Notification\Application\Command\MarkNotificationRead;
use App\Notification\Application\Command\RegisterPushSubscription;
use App\Notification\Application\Query\GetNotifications;
use App\Notification\Application\Query\GetUnreadNotificationCount;
use App\Notification\Domain\AuthenticatedNotificationRecipient;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

final readonly class NotificationController
{
    public function __construct(private MessageBusInterface $commandBus, private MessageBusInterface $queryBus, private AuthenticatedNotificationRecipient $recipients, private CsrfTokenManagerInterface $csrf, private Security $security)
    {
    }

    #[Route('/app/notifications', name: 'notification_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $recipientId = $this->recipientId();
        if (null === $recipientId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }
        $items = $this->queryBus->dispatch(new GetNotifications($recipientId))->last(HandledStamp::class)?->getResult();

        return new JsonResponse(['notifications' => \is_array($items) ? $items : [], 'unreadCount' => $this->count($recipientId)]);
    }

    #[Route('/app/notifications/{id}/read', name: 'notification_read', methods: ['POST'])]
    public function read(Request $request, string $id): JsonResponse
    {
        $recipient = $this->authorized($request);
        if ($recipient instanceof JsonResponse) {
            return $recipient;
        }
        $this->commandBus->dispatch(new MarkNotificationRead($recipient, $id));

        return new JsonResponse(['ok' => true, 'unreadCount' => $this->count($recipient)]);
    }

    #[Route('/app/notifications/read-all', name: 'notification_read_all', methods: ['POST'])]
    public function readAll(Request $request): JsonResponse
    {
        $recipient = $this->authorized($request);
        if ($recipient instanceof JsonResponse) {
            return $recipient;
        }
        $this->commandBus->dispatch(new MarkAllNotificationsRead($recipient));

        return new JsonResponse(['ok' => true, 'unreadCount' => 0]);
    }

    #[Route('/app/notifications/push-subscriptions', name: 'notification_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $recipient = $this->authorized($request);
        if ($recipient instanceof JsonResponse) {
            return $recipient;
        }
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'La suscripción no es válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $endpoint = \is_string($payload['endpoint'] ?? null) ? $payload['endpoint'] : '';
        $rawKeys = $payload['keys'] ?? null;
        $keys = \is_array($rawKeys) ? $rawKeys : [];
        try {
            $this->commandBus->dispatch(new RegisterPushSubscription($recipient, $endpoint, \is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '', \is_string($keys['auth'] ?? null) ? $keys['auth'] : ''));
        } catch (Throwable) {
            return new JsonResponse(['error' => 'No se pudo guardar la suscripción.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function recipientId(): ?string
    {
        $user = $this->securityUser();

        return null === $user ? null : $this->recipients->idForEmail($user->getUserIdentifier());
    }

    private function authorized(Request $request): string|JsonResponse
    {
        $recipientId = $this->recipientId();
        if (null === $recipientId) {
            return new JsonResponse(['error' => 'Inicia sesión para continuar.'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('notifications', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return new JsonResponse(['error' => 'La sesión ha caducado.'], 419);
        }

        return $recipientId;
    }

    private function securityUser(): ?UserInterface
    {
        $user = $this->security->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    private function count(string $recipientId): int
    {
        $count = $this->queryBus->dispatch(new GetUnreadNotificationCount($recipientId))->last(HandledStamp::class)?->getResult();

        return \is_int($count) ? $count : 0;
    }
}
