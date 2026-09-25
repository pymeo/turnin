<?php

declare(strict_types=1);

namespace App\Swap\Domain;

interface AgreementShareTokenGenerator
{
    /** @return array{token: string, reference: string} */
    public function next(): array;
}
