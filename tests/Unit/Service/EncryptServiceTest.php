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
    private string $publicKeyPem;
    private string $privateKeyPem;

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
}
