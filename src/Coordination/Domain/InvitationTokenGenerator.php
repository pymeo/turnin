<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

interface InvitationTokenGenerator
{
    public function generate(): GeneratedInvitationToken;

    public function hash(string $plain): string;
}
