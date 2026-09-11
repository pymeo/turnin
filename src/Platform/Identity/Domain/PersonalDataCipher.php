<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface PersonalDataCipher
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
