<?php

declare(strict_types=1);

namespace App\Swap\Domain;

interface AgreementTokenCipher
{
    public function encrypt(string $token): string;

    public function decrypt(string $ciphertext): string;
}
