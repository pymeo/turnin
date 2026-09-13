<?php

declare(strict_types=1);

namespace App\Coordination\Infrastructure\Identity;

use App\Coordination\Domain\GeneratedInvitationToken;
use App\Coordination\Domain\InvitationTokenGenerator;

final class CryptographicInvitationTokenGenerator implements InvitationTokenGenerator
{
    public function generate(): GeneratedInvitationToken
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new GeneratedInvitationToken($plain, $this->hash($plain));
    }

    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
