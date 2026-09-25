<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

/** `baseUrl` is the public origin, so the share texts carry absolute links. */
final readonly class GetSupervisionOverview
{
    public function __construct(public string $userId, public string $baseUrl)
    {
    }
}
