<?php

namespace App\Libraries;

use App\Exceptions\MissingKeyException;
use Config\Encryption;
use RuntimeException;

/**
 * Encryption with the site key (encryption.key in .env), in exactly the format Laravel's Crypt::encryptString uses
 * (AES-256-CBC, HMAC-SHA256, base64 JSON). Two-step login secrets saved by the Laravel version of this site can be
 * read here when encryption.key holds the same key as Laravel's APP_KEY (both accept "base64:…").
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    /** The 32-byte key. */
    public static function key(): string
    {
        $key = (string) config(Encryption::class)->key;
        if ($key === '') {
            throw new MissingKeyException();
        }
        if (strlen($key) !== 32) {
            // A key typed by hand rather than generated: stretch it to the 32 bytes AES-256 needs.
            $key = hash('sha256', $key, true);
        }

        return $key;
    }

    /** True when encryption.key is set. */
    public static function hasKey(): bool
    {
        return (string) config(Encryption::class)->key !== '';
    }

    public static function encryptString(string $value): string
    {
        $key        = self::key();
        $iv         = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $ciphertext = openssl_encrypt($value, self::CIPHER, $key, 0, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt the data.');
        }
        $iv  = base64_encode($iv);
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key);

        return base64_encode(json_encode(['iv' => $iv, 'value' => $ciphertext, 'mac' => $mac, 'tag' => ''], JSON_UNESCAPED_SLASHES));
    }

    public static function decryptString(string $payload): string
    {
        $key  = self::key();
        $data = json_decode((string) base64_decode($payload, true), true);
        if (! is_array($data) || ! isset($data['iv'], $data['value'], $data['mac']) || ! is_string($data['iv']) || ! is_string($data['value'])) {
            throw new RuntimeException('The payload is invalid.');
        }
        if (! hash_equals(hash_hmac('sha256', $data['iv'] . $data['value'], $key), (string) $data['mac'])) {
            throw new RuntimeException('The MAC is invalid.');
        }
        $plain = openssl_decrypt($data['value'], self::CIPHER, $key, 0, (string) base64_decode($data['iv'], true));
        if ($plain === false) {
            throw new RuntimeException('Could not decrypt the data.');
        }

        return $plain;
    }

    /** A keyed signature, for cookies that must not be forged. */
    public static function sign(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }
}
