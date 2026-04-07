<?php

namespace App\Service;

/**
 * Cifratura simmetrica autenticata per campi sensibili su DB.
 *
 * Algoritmo: XSalsa20-Poly1305 (sodium_crypto_secretbox)
 *   - Cifratura autenticata (AEAD): garantisce confidenzialità e integrità.
 *   - Nonce casuale da 192 bit per ogni operazione: non riutilizzabile.
 *   - Resistente ad attacchi a canale laterale (implementazione constant-time).
 *
 * Formato storage su DB: "$enc$:" + base64url(nonce || ciphertext)
 *   - Il prefisso permette di distinguere valori cifrati da quelli in chiaro.
 *   - Nonce: 24 byte (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
 *   - Ciphertext: plaintext + MAC da 16 byte (SODIUM_CRYPTO_SECRETBOX_MACBYTES)
 *
 * Generazione chiave:
 *   php -r "echo sodium_bin2hex(sodium_crypto_secretbox_keygen());"
 * → 64 caratteri hex da copiare in SQLCLIENT_ENCRYPTION_KEY nel .env.local
 */
final readonly class FieldEncryptor
{
    private const string PREFIX = '$enc$:';

    private string $key;

    public function __construct(string $encryptionKey)
    {
        $key = sodium_hex2bin($encryptionKey);

        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new \RuntimeException(sprintf('La chiave di cifratura deve essere di %d byte (%d caratteri hex).', SODIUM_CRYPTO_SECRETBOX_KEYBYTES, SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2));
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        sodium_memzero($plaintext);

        return self::PREFIX.sodium_bin2base64(
            $nonce.$ciphertext,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
    }

    /**
     * @throws \RuntimeException se il MAC non è valido (dati corrotti o chiave errata)
     */
    public function decrypt(string $encrypted): string
    {
        if (!$this->isEncrypted($encrypted)) {
            throw new \InvalidArgumentException('Il valore fornito non è cifrato con questo algoritmo.');
        }

        $raw = sodium_base642bin(
            substr($encrypted, strlen(self::PREFIX)),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );

        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Payload cifrato malformato.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if (false === $plaintext) {
            throw new \RuntimeException('Decifratura fallita: dati corrotti o chiave di cifratura errata.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
