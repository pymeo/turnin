<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use App\Platform\Identity\Domain\PersonalIdentifierFingerprinter;
use InvalidArgumentException;

final readonly class HmacPersonalIdentifierFingerprinter implements PersonalIdentifierFingerprinter
{
    public function __construct(private string $piiHmacKey)
    {
        if (
            '' === trim($piiHmacKey)
            || str_starts_with($piiHmacKey, 'replace-')
            || \strlen($piiHmacKey) < 32
        ) {
            throw new InvalidArgumentException('PII_HMAC_KEY must contain at least 32 secret characters.');
        }
    }

    public function fingerprint(string $normalizedValue): string
    {
        return hash_hmac('sha256', $normalizedValue, $this->piiHmacKey);
    }
}
