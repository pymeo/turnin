<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

interface CoordinationDisplayNames
{
    /**
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    public function forUsers(array $userIds): array;
}
