<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Security;

use App\Swap\Domain\AgreementTokenCipher;
use InvalidArgumentException;

final readonly class SodiumAgreementTokenCipher implements AgreementTokenCipher
{
    private string $key;

    public function __construct(string $agreementTokenEncryptionKey)
    {
        $decoded = base64_decode($agreementTokenEncryptionKey, true);
        if (!\is_string($decoded) || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($decoded)) {
            throw new InvalidArgumentException('AGREEMENT_TOKEN_ENCRYPTION_KEY must be base64 for exactly 32 bytes.');
        }
        $this->key = $decoded;
    }

    public function encrypt(string $token): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($token, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if (!\is_string($raw) || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new InvalidArgumentException('El enlace compartido almacenado no es válido.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if (!\is_string($plain)) {
            throw new InvalidArgumentException('No se pudo abrir el enlace compartido.');
        }

        return $plain;
    }
}
