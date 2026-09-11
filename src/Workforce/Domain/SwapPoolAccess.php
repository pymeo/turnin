<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

final readonly class SwapPoolAccess
{
    public function __construct(public SwapPoolKey $key, public string $poolId, public string $membershipId, public MembershipSource $source, public bool $primary)
    {
    }
}
