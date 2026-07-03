<?php

namespace App\Service;

/**
 * Authenticated symmetric encryption for sensitive DB fields.
 *
 * Algorithm: XSalsa20-Poly1305 (sodium_crypto_secretbox)
 *   - Authenticated encryption (AEAD): guarantees confidentiality and integrity.
 *   - Random 192-bit nonce per operation: not reusable.
 *   - Resistant to side-channel attacks (constant-time implementation).
 *
 * DB storage format: "$enc$:" + base64url(nonce || ciphertext)
 *   - The prefix allows distinguishing encrypted values from plaintext ones.
 *   - Nonce: 24 bytes (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
 *   - Ciphertext: plaintext + 16-byte MAC (SODIUM_CRYPTO_SECRETBOX_MACBYTES)
 *
 * Key generation:
 *   php -r "echo sodium_bin2hex(sodium_crypto_secretbox_keygen());"
 * → 64 hex characters to copy into SQLCLIENT_ENCRYPTION_KEY in .env.local
 */
final readonly class FieldEncryptor
{
    private const string PREFIX = '$enc$:';

    private string $key;

    public function __construct(string $encryptionKey)
    {
        $key = sodium_hex2bin($encryptionKey);

        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new \RuntimeException(sprintf('The encryption key must be %d bytes (%d hex characters).', SODIUM_CRYPTO_SECRETBOX_KEYBYTES, SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2));
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        sodium_memzero($plaintext);

        return self::PREFIX . sodium_bin2base64(
            $nonce . $ciphertext,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
    }

    /**
     * @throws \RuntimeException if the MAC is invalid (corrupted data or wrong key)
     */
    public function decrypt(string $encrypted): string
    {
        if (!$this->isEncrypted($encrypted)) {
            throw new \InvalidArgumentException('The provided value is not encrypted with this algorithm.');
        }

        $raw = sodium_base642bin(
            substr($encrypted, strlen(self::PREFIX)),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );

        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed encrypted payload.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if (false === $plaintext) {
            throw new \RuntimeException('Decryption failed: corrupted data or wrong encryption key.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
