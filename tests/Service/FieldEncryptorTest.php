<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FieldEncryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see FieldEncryptor}.
 */
final class FieldEncryptorTest extends TestCase
{
    private FieldEncryptor $encryptor;

    #[\Override]
    protected function setUp(): void
    {
        $key = sodium_bin2hex(sodium_crypto_secretbox_keygen());
        $this->encryptor = new FieldEncryptor($key);
    }

    public function testConstructorRejectsAKeyOfTheWrongLength(): void
    {
        $this->expectException(\RuntimeException::class);
        new FieldEncryptor(str_repeat('ab', 10)); // 10 bytes, not 32
    }

    public function testEncryptProducesThePrefixedFormat(): void
    {
        $cipher = $this->encryptor->encrypt('super-secret');

        $this->assertStringStartsWith('$enc$:', $cipher);
        $this->assertTrue($this->encryptor->isEncrypted($cipher));
    }

    public function testEncryptIsNonDeterministic(): void
    {
        $this->assertNotSame(
            $this->encryptor->encrypt('same-input'),
            $this->encryptor->encrypt('same-input')
        );
    }

    #[DataProvider('plaintextProvider')]
    public function testEncryptDecryptRoundTrip(string $plaintext): void
    {
        $cipher = $this->encryptor->encrypt($plaintext);

        $this->assertSame($plaintext, $this->encryptor->decrypt($cipher));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plaintextProvider(): iterable
    {
        yield 'simple' => ['hunter2'];
        yield 'empty' => [''];
        yield 'unicode' => ['pàsswörd—🔐'];
        yield 'long' => [str_repeat('A', 5000)];
    }

    public function testIsEncryptedReturnsFalseForPlainValues(): void
    {
        $this->assertFalse($this->encryptor->isEncrypted('plain text'));
        $this->assertFalse($this->encryptor->isEncrypted(''));
    }

    public function testDecryptRejectsAValueThatIsNotEncrypted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->encryptor->decrypt('just a string');
    }

    public function testDecryptFailsWhenThePayloadIsTampered(): void
    {
        $cipher = $this->encryptor->encrypt('important');

        // Flip a character well inside the base64url body to corrupt the MAC
        // while keeping the value syntactically decodable.
        $pos = \strlen($cipher) - 5;
        $cipher[$pos] = 'A' === $cipher[$pos] ? 'B' : 'A';

        $this->expectException(\RuntimeException::class);
        $this->encryptor->decrypt($cipher);
    }

    public function testDecryptRejectsATruncatedPayload(): void
    {
        $this->expectException(\RuntimeException::class);
        // Prefix + base64url of only a few bytes -> shorter than a nonce.
        $this->encryptor->decrypt('$enc$:' . sodium_bin2base64('short', \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
    }

    public function testValueEncryptedWithADifferentKeyCannotBeDecrypted(): void
    {
        $other = new FieldEncryptor(sodium_bin2hex(sodium_crypto_secretbox_keygen()));
        $cipher = $other->encrypt('cross-key');

        $this->expectException(\RuntimeException::class);
        $this->encryptor->decrypt($cipher);
    }
}
