<?php

/**
 * Unit tests for PublicKeyLoaderAdapter.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Support;

use OCA\Keepiq\Support\PublicKeyLoaderAdapter;
use PHPUnit\Framework\TestCase;
use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\Common\PublicKey;
use phpseclib4\File\X509;
use Throwable;

/**
 * Tests for PublicKeyLoaderAdapter: plain and password-protected keys and certificates load through it.
 *
 * @covers \OCA\Keepiq\Support\PublicKeyLoaderAdapter
 */
class PublicKeyLoaderAdapterTest extends TestCase {

	/**
	 * One generated key pair and certificate: privatePem, encryptedPem, publicPem, certificatePem.
	 *
	 * @var array<string, string>
	 */
	private static array $key = [];

	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $privatePem);
		openssl_pkey_export($key, $encryptedPem, 'hunter2');
		$publicPem = openssl_pkey_get_details($key)['key'];
		$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'keepiq-test'], $key), null, $key, 1);
		openssl_x509_export($cert, $certificatePem);
		self::$key = [
			'private' => $privatePem,
			'encrypted' => $encryptedPem,
			'public' => $publicPem,
			'certificate' => $certificatePem,
		];
	}//end setUpBeforeClass()

	/**
	 * The modulus of a PEM key, as OpenSSL sees it.
	 *
	 * @param string $pem A public or private key PEM.
	 *
	 * @return string The modulus bytes.
	 */
	private static function modulus(string $pem): string {
		$key = openssl_pkey_get_private($pem);
		if ($key === false) {
			$key = openssl_pkey_get_public($pem);
		}

		return openssl_pkey_get_details($key)['rsa']['n'];
	}//end modulus()

	/**
	 * A public key PEM loads as that public key.
	 *
	 * @return void
	 */
	public function testLoadReadsAPublicKey(): void {
		$loaded = (new PublicKeyLoaderAdapter())->load(self::$key['public']);

		$this->assertInstanceOf(PublicKey::class, $loaded);
		$this->assertSame(self::modulus(self::$key['public']), self::modulus($loaded->toString('PKCS8')));
	}//end testLoadReadsAPublicKey()

	/**
	 * A password-protected private key loads with its password.
	 *
	 * @return void
	 */
	public function testLoadWithAPasswordReadsAnEncryptedKey(): void {
		$loaded = (new PublicKeyLoaderAdapter())->load(self::$key['encrypted'], 'hunter2');

		$this->assertInstanceOf(PrivateKey::class, $loaded);
	}//end testLoadWithAPasswordReadsAnEncryptedKey()

	/**
	 * A private key loads as the same private key, with or without a password.
	 *
	 * @return void
	 */
	public function testLoadPrivateKeyWithAndWithoutPassword(): void {
		$adapter = new PublicKeyLoaderAdapter();

		$plain = $adapter->loadPrivateKey(self::$key['private']);
		$unlocked = $adapter->loadPrivateKey(self::$key['encrypted'], 'hunter2');

		$expected = self::modulus(self::$key['private']);
		$this->assertSame($expected, self::modulus($plain->getPublicKey()->toString('PKCS8')));
		$this->assertSame($expected, self::modulus($unlocked->getPublicKey()->toString('PKCS8')));
	}//end testLoadPrivateKeyWithAndWithoutPassword()

	/**
	 * An encrypted key does not open without its password, nor with a wrong one.
	 *
	 * @return void
	 */
	public function testAnEncryptedKeyNeedsItsPassword(): void {
		$adapter = new PublicKeyLoaderAdapter();

		foreach (['', 'wrong'] as $password) {
			try {
				$adapter->loadPrivateKey(self::$key['encrypted'], $password);
				$this->fail('The encrypted key opened with password "' . $password . '".');
			} catch (Throwable $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}//end testAnEncryptedKeyNeedsItsPassword()

	/**
	 * A certificate PEM loads with its subject.
	 *
	 * @return void
	 */
	public function testLoadCertificateReadsTheSubject(): void {
		$certificate = (new PublicKeyLoaderAdapter())->loadCertificate(self::$key['certificate']);

		$this->assertInstanceOf(X509::class, $certificate);
		$this->assertSame(
			['keepiq-test'],
			array_map(static fn (object $value): string => (string)$value->value, $certificate->getSubjectDNProps('id-at-commonName'))
		);
	}//end testLoadCertificateReadsTheSubject()
}//end class
