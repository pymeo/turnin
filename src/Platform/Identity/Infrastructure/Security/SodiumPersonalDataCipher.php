<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use App\Platform\Identity\Domain\PersonalDataCipher;
use InvalidArgumentException;
use RuntimeException;
use SodiumException;

final readonly class SodiumPersonalDataCipher implements PersonalDataCipher
{
    private string $key;

    public function __construct(string $piiEncryptionKey)
    {
        try {
            $key = sodium_base642bin($piiEncryptionKey, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new InvalidArgumentException('PII_ENCRYPTION_KEY must be valid base64.');
        }
        if (\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
            throw new InvalidArgumentException('PII_ENCRYPTION_KEY must decode to 32 bytes.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->key);

        return sodium_bin2base64($nonce.$ciphertext, \SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $ciphertext): string
    {
        try {
            $payload = sodium_base642bin($ciphertext, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new RuntimeException('The protected value is not valid base64.');
        }
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, $nonceLength), '', substr($payload, 0, $nonceLength), $this->key);
        if (false === $plaintext) {
            throw new RuntimeException('The protected value cannot be decrypted.');
        }

        return $plaintext;
    }
}
