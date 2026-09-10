<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface ExternalIdentities
{
    public function byProviderSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity;

    public function byUserAndProvider(UserId $userId, ExternalIdentityProvider $provider): ?ExternalIdentity;

    public function save(ExternalIdentity $identity): void;
}
