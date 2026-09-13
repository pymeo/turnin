<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

final readonly class GeneratedInvitationToken
{
    public function __construct(public string $plain, public string $hash)
    {
    }
}
