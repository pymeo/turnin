<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExternalIdentity
{
    public function __construct(public string $id, public UserId $userId, public ExternalIdentityProvider $provider, public string $providerSubject, public Email $emailAtLinkTime, public DateTimeImmutable $createdAt)
    {
        if ('' === trim($id) || '' === trim($providerSubject)) {
            throw new InvalidArgumentException('An external identity needs an id and provider subject.');
        }
    }
}
