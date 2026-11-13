<?php

/**
 * Keepiq ArchiveCipher Test
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Backup
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

namespace OCA\Keepiq\Tests\Unit\Backup;

use InvalidArgumentException;
use OCA\Keepiq\Backup\ArchiveCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Key checks and the refusals of an encrypted archive that is not whole.
 */
#[CoversClass(ArchiveCipher::class)]
class ArchiveCipherTest extends TestCase {

	/**
	 * Scratch directory of the test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * A scratch directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/keepiq-cipher-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir, 0700, true);
	}//end setUp()

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * An RSA key pair.
	 *
	 * @return array{0:string,1:string} Public and private PEM
	 */
	private function rsaPair(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $private);
		return [openssl_pkey_get_details($key)['key'], $private];
	}//end rsaPair()

	/**
	 * An encrypted archive of the given plaintext.
	 *
	 * @param string $publicPem The recipient key
	 * @param string $plain     The plaintext
	 *
	 * @return string Path of the encrypted file
	 */
	private function encrypted(string $publicPem, string $plain): string {
		file_put_contents($this->dir . '/plain.zip', $plain);
		(new ArchiveCipher())->encryptFile(plainPath: $this->dir . '/plain.zip', outPath: $this->dir . '/enc.bin', publicPem: $publicPem);
		return $this->dir . '/enc.bin';
	}//end encrypted()

	/**
	 * An RSA public key is accepted; text that is no key is not.
	 *
	 * @return void
	 */
	public function testIsValidPublicKeyAcceptsRsaAndRefusesGarbage(): void {
		[$public] = $this->rsaPair();
		$cipher = new ArchiveCipher();

		$this->assertTrue($cipher->isValidPublicKey(pem: $public));
		$this->assertFalse($cipher->isValidPublicKey(pem: 'not a key'));
	}//end testIsValidPublicKeyAcceptsRsaAndRefusesGarbage()

	/**
	 * A well-formed public key that is not RSA is refused, for checks and
	 * for encryption alike.
	 *
	 * @return void
	 */
	public function testANonRsaKeyIsRefused(): void {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		$public = openssl_pkey_get_details($key)['key'];
		$cipher = new ArchiveCipher();

		$this->assertFalse($cipher->isValidPublicKey(pem: $public));

		file_put_contents($this->dir . '/plain.zip', 'x');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only RSA keys are supported');
		$cipher->encryptFile(plainPath: $this->dir . '/plain.zip', outPath: $this->dir . '/enc.bin', publicPem: $public);
	}//end testANonRsaKeyIsRefused()

	/**
	 * Encrypting to text that is no key is refused before anything is written.
	 *
	 * @return void
	 */
	public function testEncryptingToGarbageIsRefused(): void {
		file_put_contents($this->dir . '/plain.zip', 'x');

		try {
			(new ArchiveCipher())->encryptFile(plainPath: $this->dir . '/plain.zip', outPath: $this->dir . '/enc.bin', publicPem: 'nope');
			$this->fail('Encrypted to a non-key');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('Not a public key or certificate', $exception->getMessage());
		}

		$this->assertFileDoesNotExist($this->dir . '/enc.bin');
	}//end testEncryptingToGarbageIsRefused()

	/**
	 * A file without the magic header is not decrypted.
	 *
	 * @return void
	 */
	public function testDecryptingAPlainFileIsRefused(): void {
		[, $private] = $this->rsaPair();
		file_put_contents($this->dir . '/plain.zip', 'PK-just-a-zip');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Not an encrypted Keepiq backup');
		(new ArchiveCipher())->decryptFile(encPath: $this->dir . '/plain.zip', outPath: $this->dir . '/out.zip', privatePem: $private);
	}//end testDecryptingAPlainFileIsRefused()

	/**
	 * A private key text that cannot be loaded does not open the archive.
	 *
	 * @return void
	 */
	public function testAnUnloadablePrivateKeyDoesNotOpen(): void {
		[$public] = $this->rsaPair();
		$enc = $this->encrypted(publicPem: $public, plain: 'DATA');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The key does not open this backup');
		(new ArchiveCipher())->decryptFile(encPath: $enc, outPath: $this->dir . '/out.zip', privatePem: 'not a private key');
	}//end testAnUnloadablePrivateKeyDoesNotOpen()

	/**
	 * An archive cut right after its header, with no segment at all, is
	 * refused even though the key opens it.
	 *
	 * @return void
	 */
	public function testAHeaderWithoutSegmentsIsRefused(): void {
		[$public, $private] = $this->rsaPair();
		$encrypted = (string)file_get_contents($this->encrypted(publicPem: $public, plain: 'DATA'));
		$headerLength = strlen(ArchiveCipher::MAGIC) + 4 + unpack('N', substr($encrypted, strlen(ArchiveCipher::MAGIC), 4))[1];
		file_put_contents($this->dir . '/header.bin', substr($encrypted, 0, $headerLength));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The backup was changed or cut off');
		(new ArchiveCipher())->decryptFile(encPath: $this->dir . '/header.bin', outPath: $this->dir . '/out.zip', privatePem: $private);
	}//end testAHeaderWithoutSegmentsIsRefused()

	/**
	 * An empty plaintext round-trips as one final segment.
	 *
	 * @return void
	 */
	public function testAnEmptyArchiveRoundTrips(): void {
		[$public, $private] = $this->rsaPair();
		$enc = $this->encrypted(publicPem: $public, plain: '');
		$cipher = new ArchiveCipher();

		$this->assertTrue($cipher->isEncrypted(path: $enc));
		$cipher->decryptFile(encPath: $enc, outPath: $this->dir . '/out.zip', privatePem: $private);
		$this->assertSame('', file_get_contents($this->dir . '/out.zip'));
	}//end testAnEmptyArchiveRoundTrips()

	/**
	 * The header names the format, cipher and key wrap it was written with.
	 *
	 * @return void
	 */
	public function testTheHeaderDescribesTheFormat(): void {
		[$public] = $this->rsaPair();
		$encrypted = (string)file_get_contents($this->encrypted(publicPem: $public, plain: 'DATA'));
		$length = unpack('N', substr($encrypted, strlen(ArchiveCipher::MAGIC), 4))[1];
		$header = json_decode(substr($encrypted, strlen(ArchiveCipher::MAGIC) + 4, $length), true);

		$this->assertSame('keepiq-vault-backup-enc-v1', $header['format']);
		$this->assertSame('AES-256-GCM', $header['cipher']);
		$this->assertSame('RSA-OAEP-SHA256', $header['keyWrap']);
		$this->assertSame(1048576, $header['segmentSize']);
		$this->assertNotFalse(base64_decode($header['wrappedKey'], true));
	}//end testTheHeaderDescribesTheFormat()
}//end class
