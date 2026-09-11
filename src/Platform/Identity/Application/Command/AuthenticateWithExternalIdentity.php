<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

use App\Platform\Identity\Domain\ExternalIdentityProvider;

final readonly class AuthenticateWithExternalIdentity
{
    public function __construct(public ExternalIdentityProvider $provider, public string $subject, public string $email, public bool $emailVerified, public ?string $givenName = null, public ?string $familyName = null)
    {
    }
}
