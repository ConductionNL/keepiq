<?php

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Service\DecryptService;
use OCA\Doriath\Service\EncryptService;
use PHPUnit\Framework\TestCase;

class EncryptServiceTest extends TestCase
{
    private EncryptService $encrypt;
    private DecryptService $decrypt;
    private string $publicKeyPem = '';
    private string $privateKeyPem = '';

    protected function setUp(): void
    {
        $this->encrypt = new EncryptService();
        $this->decrypt = new DecryptService();

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyPair, $this->privateKeyPem);
        $details = openssl_pkey_get_details($keyPair);
        $this->publicKeyPem = $details['key'];
    }

    public function testRsaEncryptDecryptSingleChunk(): void
    {
        $plaintext = 'short secret';
        $ciphertext = $this->encrypt->rsaEncrypt($plaintext, $this->publicKeyPem);

        $this->assertNotEmpty($ciphertext);
        $this->assertNotEquals($plaintext, $ciphertext);

        $decrypted = $this->decrypt->rsaDecrypt($ciphertext, $this->privateKeyPem);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testRsaEncryptDecryptMultiChunk(): void
    {
        // 1000 bytes > 446 chunk size → 3 chunks.
        $plaintext = str_repeat('A', 1000);
        $ciphertext = $this->encrypt->rsaEncrypt($plaintext, $this->publicKeyPem);

        $decrypted = $this->decrypt->rsaDecrypt($ciphertext, $this->privateKeyPem);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testRsaEncryptDecryptLargePayload(): void
    {
        // 10000 bytes → 23 chunks.
        $plaintext = str_repeat('X', 10000);
        $ciphertext = $this->encrypt->rsaEncrypt($plaintext, $this->publicKeyPem);

        $decrypted = $this->decrypt->rsaDecrypt($ciphertext, $this->privateKeyPem);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testAesGcmEnvelopeRoundTrip(): void
    {
        $key = random_bytes(32);
        $plaintext = 'secret data';

        $envelope = $this->encrypt->aesEncrypt($plaintext, $key);
        $this->assertNotEmpty($envelope);

        $decrypted = $this->decrypt->aesDecrypt($envelope, $key);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testAesGcmWrongKeyFails(): void
    {
        $key = random_bytes(32);
        $wrongKey = random_bytes(32);

        $envelope = $this->encrypt->aesEncrypt('secret', $key);

        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->decrypt->aesDecrypt($envelope, $wrongKey);
    }

    public function testEncryptDecryptPrivateKeyWithPassword(): void
    {
        $password = 'test-master-password-2024!';

        $encrypted = $this->encrypt->encryptPrivateKey($this->privateKeyPem, $password);
        $this->assertNotEmpty($encrypted);

        $decrypted = $this->decrypt->decryptPrivateKey($encrypted, $password);
        $this->assertEquals($this->privateKeyPem, $decrypted);
    }

    public function testEncryptPrivateKeyWrongPasswordFails(): void
    {
        $password = 'correct-password';
        $wrongPassword = 'wrong-password';

        $encrypted = $this->encrypt->encryptPrivateKey($this->privateKeyPem, $password);

        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->decrypt->decryptPrivateKey($encrypted, $wrongPassword);
    }

    public function testRsaEncryptInvalidPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->encrypt->rsaEncrypt('data', 'not-a-pem-key');
    }

    public function testDeriveKeyConsistency(): void
    {
        $salt = random_bytes(16);
        $key1 = $this->encrypt->deriveKey('password', $salt);
        $key2 = $this->encrypt->deriveKey('password', $salt);

        $this->assertEquals($key1, $key2);
        $this->assertEquals(32, strlen($key1));
    }

    public function testDeriveKeyDifferentPasswordsProduceDifferentKeys(): void
    {
        $salt = random_bytes(16);
        $key1 = $this->encrypt->deriveKey('password1', $salt);
        $key2 = $this->encrypt->deriveKey('password2', $salt);

        $this->assertNotEquals($key1, $key2);
    }

    public function testRsaEncryptEmptyString(): void
    {
        $ciphertext = $this->encrypt->rsaEncrypt('', $this->publicKeyPem);
        $decrypted = $this->decrypt->rsaDecrypt($ciphertext, $this->privateKeyPem);

        $this->assertSame('', $decrypted);
    }

    public function testRsaEncryptExactlyOneChunkBoundary(): void
    {
        // Exactly 446 bytes = exactly 1 chunk.
        $plaintext = str_repeat('B', 446);
        $ciphertext = $this->encrypt->rsaEncrypt($plaintext, $this->publicKeyPem);
        $decrypted = $this->decrypt->rsaDecrypt($ciphertext, $this->privateKeyPem);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testRsaDecryptInvalidBase64(): void
    {
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid RSA ciphertext format');
        $this->decrypt->rsaDecrypt('!!!not-base64!!!', $this->privateKeyPem);
    }

    public function testRsaDecryptTooShortPayload(): void
    {
        // Valid base64 but too short (less than 4 bytes).
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid RSA ciphertext format');
        $this->decrypt->rsaDecrypt(base64_encode('ab'), $this->privateKeyPem);
    }

    public function testRsaDecryptLengthMismatch(): void
    {
        // Header says 2 chunks but only provide data for 1.
        $header = pack('N', 2);
        $fakeBlock = str_repeat("\0", 512);
        $raw = $header . $fakeBlock;

        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('length mismatch');
        $this->decrypt->rsaDecrypt(base64_encode($raw), $this->privateKeyPem);
    }

    public function testRsaDecryptInvalidPrivateKey(): void
    {
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid private key PEM');
        $this->decrypt->rsaDecrypt(base64_encode(pack('N', 0)), 'not-a-key');
    }

    public function testAesDecryptInvalidBase64(): void
    {
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid base64 envelope');
        $this->decrypt->aesDecrypt('!!!invalid-base64!!!', random_bytes(32));
    }

    public function testAesDecryptEnvelopeTooShort(): void
    {
        // Minimum length is 4 + 16 + 12 + 16 = 48. Send less.
        $short = base64_encode(str_repeat("\0", 10));
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Envelope too short');
        $this->decrypt->aesDecrypt($short, random_bytes(32));
    }

    public function testAesDecryptWrongVersionByte(): void
    {
        // Build an envelope with version 99 instead of 1.
        $envelope = pack('N', 99)
            . str_repeat("\0", 16)  // salt
            . random_bytes(12)      // IV
            . random_bytes(32)      // ciphertext
            . random_bytes(16);     // tag

        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Unsupported envelope version: 99');
        $this->decrypt->aesDecrypt(base64_encode($envelope), random_bytes(32));
    }

    public function testDecryptPrivateKeyInvalidEnvelope(): void
    {
        $short = base64_encode(str_repeat("\0", 10));
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Envelope too short');
        $this->decrypt->decryptPrivateKey($short, 'password');
    }

    public function testDecryptPrivateKeyWrongVersion(): void
    {
        $envelope = pack('N', 42)
            . random_bytes(16)  // salt
            . random_bytes(12)  // IV
            . random_bytes(32)  // ciphertext
            . random_bytes(16); // tag

        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Unsupported envelope version: 42');
        $this->decrypt->decryptPrivateKey(base64_encode($envelope), 'password');
    }

    public function testDecryptPrivateKeyInvalidBase64(): void
    {
        $this->expectException(\OCA\Doriath\Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid base64 envelope');
        $this->decrypt->decryptPrivateKey('!!!bad-base64!!!', 'password');
    }
}
