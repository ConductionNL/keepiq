<?php

/**
 * The server decrypts what every client library encrypts.
 *
 * Each library in `sdk/` writes `sdk/testdata/encrypted_by_<language>.json`:
 * the values of `sdk/testdata/vector_plaintexts.json`, encrypted by that
 * library to the throwaway key in `sdk/testdata/machine_envelope.json`. This
 * test decrypts every one of them with the real DecryptService, so a library
 * whose write-back the server could not read turns this test red.
 *
 * To regenerate a vector after the fixture key changed, run that library's
 * tests with KEEPIQ_WRITE_VECTORS=1 (see sdk/README.md).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\DecryptService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Library-encrypted vectors round-trip through DecryptService.
 *
 * @spec openspec/changes/apps-client-libraries-and-ci/tasks.md#1.4
 */
class SdkEncryptedVectorsTest extends TestCase {

	/**
	 * The shared vector directory.
	 *
	 * @var string
	 */
	private const TESTDATA = __DIR__ . '/../../../sdk/testdata/';

	/**
	 * Every library that must produce a vector. A missing file is a failure,
	 * not a skip.
	 *
	 * @return array<string,array{string}>
	 */
	public static function libraries(): array {
		return [
			'go' => ['go'],
			'python' => ['python'],
			'js' => ['js'],
		];
	}//end libraries()

	/**
	 * Every value the library encrypted decrypts to its plaintext.
	 *
	 * @param string $language The library's vector suffix
	 *
	 * @return void
	 */
	#[DataProvider('libraries')]
	public function testDecryptServiceReadsTheLibraryVector(string $language): void {
		$path = self::TESTDATA . 'encrypted_by_' . $language . '.json';
		$this->assertFileExists($path);

		$fixture = json_decode((string)file_get_contents(self::TESTDATA . 'machine_envelope.json'), true, 512, JSON_THROW_ON_ERROR);
		$expected = json_decode((string)file_get_contents(self::TESTDATA . 'vector_plaintexts.json'), true, 512, JSON_THROW_ON_ERROR);
		$vector = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		// Key order is the producer's (Go sorts map keys), so compare sorted.
		$want = $expected['plaintext'];
		$got = $vector['plaintext'];
		$ciphertextKeys = array_keys($vector['ciphertext']);
		ksort($want);
		ksort($got);
		sort($ciphertextKeys);
		$this->assertSame($want, $got, $language . ' vector holds other plaintexts than vector_plaintexts.json');
		$this->assertSame(array_keys($want), $ciphertextKeys);

		$decrypt = new DecryptService();
		foreach ($expected['plaintext'] as $name => $plaintext) {
			$this->assertSame(
				$plaintext,
				$decrypt->rsaDecrypt($vector['ciphertext'][$name], $fixture['privateKeyPem']),
				$language . ' ciphertext.' . $name . ' does not decrypt with DecryptService'
			);
		}
	}//end testDecryptServiceReadsTheLibraryVector()
}//end class
