<?php

/**
 * Unit tests for MachineSecretEnvelopeService.
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

use DateTime;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the doriath-machine-secret-v1 envelope, ETag derivation, and
 * certificate fingerprinting.
 */
class MachineSecretEnvelopeServiceTest extends TestCase {

	private EncryptionSuiteMapper $suiteMapper;

	private FolderMapper $folderMapper;

	private MachineSecretEnvelopeService $service;

	private string $certificatePem;

	/**
	 * Set up mocks and a self-signed certificate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->folderMapper = $this->createMock(FolderMapper::class);
		$this->service = new MachineSecretEnvelopeService(
			suiteMapper: $this->suiteMapper,
			folderMapper: $this->folderMapper,
		);

		$config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
		$pkey = openssl_pkey_new($config);
		$csr = openssl_csr_new(['CN' => 'env-test'], $pkey, $config);
		$cert = openssl_csr_sign($csr, null, $pkey, 1, $config);
		openssl_x509_export($cert, $certPem);
		$this->certificatePem = $certPem;
	}//end setUp()

	/**
	 * Build a fully-populated secret entity.
	 *
	 * @return Secret
	 */
	private function makeSecret(): Secret {
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setName('zgw-api-token');
		$secret->setUrl('https://example.test');
		$secret->setTypeId('api_key');
		$secret->setFolderId('fol-1');
		$secret->setKey('CIPHER-KEY');
		$secret->setLogin('CIPHER-LOGIN');
		$secret->setAdditionalFields('CIPHER-EXTRA');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-1');
		$secret->setCreatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$secret->setUpdatedAt(new DateTime('2026-01-02T00:00:00+00:00'));
		$secret->setKeyUpdatedAt(new DateTime('2026-01-02T00:00:00+00:00'));
		return $secret;
	}//end makeSecret()

	/**
	 * The envelope carries the format, self-describing encryption block,
	 * metadata, and ciphertext fields.
	 *
	 * @return void
	 */
	public function testEnvelopeIsSelfDescribing(): void {
		$suite = new EncryptionSuite();
		$suite->setCertificate($this->certificatePem);
		$this->suiteMapper->method('findById')->willReturn($suite);
		$this->folderMapper->method('getPath')->willReturn('infra/zgw');

		$env = $this->service->serialize($this->makeSecret());

		$this->assertSame('doriath-machine-secret-v1', $env['format']);
		$this->assertSame('zgw-api-token', $env['secret']['name']);
		$this->assertSame('infra/zgw', $env['secret']['folderPath']);
		$this->assertSame('api_key', $env['secret']['type']);
		$this->assertSame('suite-1', $env['encryption']['suiteId']);
		$this->assertStringStartsWith('sha256:', $env['encryption']['certificateFingerprint']);
		$this->assertSame('rsa-oaep-sha256-chunked-v1', $env['encryption']['scheme']);
		$this->assertSame('CIPHER-KEY', $env['ciphertext']['key']);
		$this->assertSame('CIPHER-LOGIN', $env['ciphertext']['login']);
		$this->assertSame('CIPHER-EXTRA', $env['ciphertext']['additionalFields']);
	}//end testEnvelopeIsSelfDescribing()

	/**
	 * The envelope exposes no plaintext-capable field — only the named
	 * ciphertext keys exist under `ciphertext`.
	 *
	 * @return void
	 */
	public function testEnvelopeExposesCiphertextOnly(): void {
		$this->suiteMapper->method('findById')
			->willThrowException(new DoesNotExistException('none'));

		$env = $this->service->serialize($this->makeSecret());

		$this->assertSame(
			['key', 'login', 'additionalFields'],
			array_keys($env['ciphertext'])
		);
		// No 'value'/'plaintext'/'password' field anywhere in the envelope.
		$flat = json_encode($env);
		$this->assertStringNotContainsString('"plaintext"', $flat);
		$this->assertStringNotContainsString('"value"', $flat);
	}//end testEnvelopeExposesCiphertextOnly()

	/**
	 * keyUpdatedAt is nullable in the envelope.
	 *
	 * @return void
	 */
	public function testNullableKeyUpdatedAt(): void {
		$this->suiteMapper->method('findById')
			->willThrowException(new DoesNotExistException('none'));

		$secret = $this->makeSecret();
		$secret->setKeyUpdatedAt(null);

		$env = $this->service->serialize($secret);
		$this->assertNull($env['secret']['keyUpdatedAt']);
	}//end testNullableKeyUpdatedAt()

	/**
	 * A root-level secret (no folder) yields an empty folder path and never
	 * calls the folder mapper.
	 *
	 * @return void
	 */
	public function testRootSecretHasEmptyFolderPath(): void {
		$this->suiteMapper->method('findById')
			->willThrowException(new DoesNotExistException('none'));
		$this->folderMapper->expects($this->never())->method('getPath');

		$secret = $this->makeSecret();
		$secret->setFolderId(null);

		$env = $this->service->serialize($secret);
		$this->assertSame('', $env['secret']['folderPath']);
	}//end testRootSecretHasEmptyFolderPath()

	/**
	 * The certificate fingerprint is the sha256 of the cert DER, stable and
	 * prefixed.
	 *
	 * @return void
	 */
	public function testCertificateFingerprintMatchesDer(): void {
		$suite = new EncryptionSuite();
		$suite->setCertificate($this->certificatePem);
		$this->suiteMapper->method('findById')->willReturn($suite);

		$fp = $this->service->certificateFingerprint('suite-1');

		preg_match(
			'/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
			$this->certificatePem,
			$m
		);
		$der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
		$expected = 'sha256:' . hash('sha256', $der);

		$this->assertSame($expected, $fp);
	}//end testCertificateFingerprintMatchesDer()

	/**
	 * The ETag is stable across no-op reads and changes when ciphertext
	 * changes.
	 *
	 * @return void
	 */
	public function testEtagStableAndChanges(): void {
		$a = $this->makeSecret();
		$b = $this->makeSecret();
		$this->assertSame($this->service->etag($a), $this->service->etag($b));

		$b->setKey('CIPHER-KEY-ROTATED');
		$this->assertNotSame($this->service->etag($a), $this->service->etag($b));
	}//end testEtagStableAndChanges()

	/**
	 * The candidate descriptor carries only non-sensitive metadata, no
	 * ciphertext.
	 *
	 * @return void
	 */
	public function testCandidateHasNoCiphertext(): void {
		$this->folderMapper->method('getPath')->willReturn('infra/zgw');

		$cand = $this->service->candidate($this->makeSecret());

		$this->assertSame(['id', 'name', 'folderPath', 'updatedAt'], array_keys($cand));
		$this->assertArrayNotHasKey('key', $cand);
	}//end testCandidateHasNoCiphertext()
}//end class
