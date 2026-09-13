<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

interface CurrentCoordinationUser
{
    public function id(): ?string;
}
