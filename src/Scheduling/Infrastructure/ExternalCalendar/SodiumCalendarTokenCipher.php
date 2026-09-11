<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\ExternalCalendar;

use App\Scheduling\Application\ExternalCalendar\CalendarTokenCipher;
use InvalidArgumentException;
use RuntimeException;
use SodiumException;

final readonly class SodiumCalendarTokenCipher implements CalendarTokenCipher
{
    private string $key;

    public function __construct(string $calendarTokenEncryptionKey)
    {
        try {
            $key = sodium_base642bin($calendarTokenEncryptionKey, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new InvalidArgumentException('CALENDAR_TOKEN_ENCRYPTION_KEY must be valid base64.');
        }
        if (\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
            throw new InvalidArgumentException('CALENDAR_TOKEN_ENCRYPTION_KEY must decode to 32 bytes.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return sodium_bin2base64($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->key), \SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $ciphertext): string
    {
        try {
            $payload = sodium_base642bin($ciphertext, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new RuntimeException('The protected Calendar token is not valid base64.');
        }
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, $nonceLength), '', substr($payload, 0, $nonceLength), $this->key);
        if (false === $plain) {
            throw new RuntimeException('The protected Calendar token cannot be decrypted.');
        }

        return $plain;
    }
}
