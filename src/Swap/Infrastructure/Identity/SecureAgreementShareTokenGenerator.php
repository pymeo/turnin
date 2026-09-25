<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Identity;

use App\Swap\Domain\AgreementShareTokenGenerator;

final readonly class SecureAgreementShareTokenGenerator implements AgreementShareTokenGenerator
{
    public function next(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bytes = random_bytes(6);
        $reference = '';
        for ($index = 0; $index < 6; ++$index) {
            $reference .= $alphabet[\ord($bytes[$index]) % \strlen($alphabet)];
        }

        return ['token' => $token, 'reference' => $reference];
    }
}
