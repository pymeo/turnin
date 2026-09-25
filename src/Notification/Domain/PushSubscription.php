<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PushSubscription
{
    public function __construct(
        public string $id,
        public string $recipientId,
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id) || '' === trim($recipientId) || !str_starts_with($endpoint, 'https://') || '' === trim($publicKey) || '' === trim($authToken)) {
            throw new InvalidArgumentException('La suscripción push no es válida.');
        }
    }
}
