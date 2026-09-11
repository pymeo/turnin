<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface PersonalIdentifierFingerprinter
{
    public function fingerprint(string $normalizedValue): string;
}
