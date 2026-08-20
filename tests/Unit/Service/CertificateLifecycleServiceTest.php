<?php

/**
 * Unit tests for CertificateLifecycleService (certificate-lifecycle §6).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\CertificateMetadata;
use OCA\Doriath\Db\CertificateMetadataMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Service\CertificateIssuanceService;
use OCA\Doriath\Service\CertificateLifecycleService;
use OCA\Doriath\Service\CertificateMetadataService;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CertificateLifecycleService.
 */
class CertificateLifecycleServiceTest extends TestCase {
	private CertificateLifecycleService $service;

	private CertificateMetadataMapper&MockObject $metadataMapper;

	private SecretMapper&MockObject $secretMapper;

	private SecretTypeMapper&MockObject $typeMapper;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private SecretService&MockObject $secretService;

	private CertificateIssuanceService&MockObject $issuanceService;

	/**
	 * A self-signed PEM generated once per test run for server-parse
	 * assertions.
	 *
	 * @var string
	 */
	private string $pem = '';

	/**
	 * Build the service over mocked collaborators + a real PEM.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->metadataMapper = $this->createMock(originalClassName: CertificateMetadataMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->typeMapper = $this->createMock(originalClassName: SecretTypeMapper::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->secretService = $this->createMock(originalClassName: SecretService::class);
		$this->issuanceService = $this->createMock(originalClassName: CertificateIssuanceService::class);

		$this->service = new CertificateLifecycleService(
			metadataMapper: $this->metadataMapper,
			secretMapper: $this->secretMapper,
			suiteMapper: $this->suiteMapper,
			caMapper: $this->createMock(originalClassName: CACertificateMapper::class),
			metadataService: new CertificateMetadataService(
				metadataMapper: $this->metadataMapper,
				secretMapper: $this->secretMapper,
				typeMapper: $this->typeMapper,
				secretService: $this->secretService,
			),
			issuanceService: $this->issuanceService,
			eventDispatcher: null,
		);

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => 'suite-test.doriath'], $key, ['digest_alg' => 'sha256']);
		$x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
		openssl_x509_export(certificate: $x509, output: $pemOut);
		$this->pem = $pemOut;
	}//end setUp()

	/**
	 * Register the 'certificate' system type with the mock.
	 *
	 * @return void
	 */
	private function seedCertType(): void {
		$type = new SecretType();
		$type->setId('type-cert');
		$this->typeMapper->method('findByName')->with('certificate')->willReturn($type);
	}//end seedCertType()

	/**
	 * A certificate-type secret owned by alice.
	 *
	 * @return Secret
	 */
	private function makeSecret(): Secret {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setTypeId('type-cert');
		$secret->setName('prod TLS cert');

		return $secret;
	}//end makeSecret()

	/**
	 * Inventory rows are provenance-tagged and expose no PEM,
	 * ciphertext, or key material (§6.1).
	 *
	 * @return void
	 */
	public function testInventoryTagsSourcesAndLeaksNothing(): void {
		$this->seedCertType();
		$metadata = new CertificateMetadata();
		$metadata->setId('meta-1');
		$metadata->setSecretId('secret-1');
		$metadata->setSubject('CN=stored.example');
		$this->metadataMapper->method('findByOwner')->willReturn(['secret-1' => $metadata]);
		$this->secretMapper->method('findByOwner')->willReturn([$this->makeSecret()]);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setCertificate($this->pem);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$inventory = $this->service->inventory(userId: 'alice', isAdmin: false);

		$this->assertSame('client_parsed', $inventory['stored'][0]['metadataSource']);
		$this->assertSame('CN=stored.example', $inventory['stored'][0]['metadata']['subject']);
		$this->assertSame('server_parsed', $inventory['suites'][0]['metadataSource']);
		$this->assertStringContainsString('CN=suite-test.doriath', $inventory['suites'][0]['metadata']['subject']);
		$this->assertSame([], $inventory['ca'], 'non-admins see no CA rows');

		$json = (string)json_encode($inventory);
		$this->assertStringNotContainsString('BEGIN CERTIFICATE', $json);
		$this->assertStringNotContainsString('PRIVATE KEY', $json);
	}//end testInventoryTagsSourcesAndLeaksNothing()

	/**
	 * Server-side parse extracts subject/issuer/notAfter and rejects
	 * garbage (§6.1).
	 *
	 * @return void
	 */
	public function testParseCaCertificate(): void {
		$parsed = $this->service->parseCaCertificate(pem: $this->pem);
		$this->assertNotNull($parsed);
		$this->assertStringContainsString('CN=suite-test.doriath', $parsed['subject']);
		$this->assertNotNull($parsed['notAfter']);
		$this->assertStringStartsWith('sha256:', $parsed['fingerprintSha256']);

		$this->assertNull($this->service->parseCaCertificate(pem: 'not a pem'));
	}//end testParseCaCertificate()

	/**
	 * Cross-owner metadata submission is rejected before any write
	 * (§6.1).
	 *
	 * @return void
	 */
	public function testSubmitMetadataRejectsCrossOwner(): void {
		$this->seedCertType();
		$this->secretMapper->method('findById')->willReturn($this->makeSecret());
		$this->metadataMapper->expects($this->never())->method('insert');
		$this->secretService->expects($this->never())->method('setExpiry');

		$this->expectException(InvalidArgumentException::class);
		$this->service->submitMetadata(secretId: 'secret-1', userId: 'mallory', fields: ['notAfter' => '2030-01-01T00:00:00Z']);
	}//end testSubmitMetadataRejectsCrossOwner()

	/**
	 * Submission on a non-certificate secret is rejected (§6.1).
	 *
	 * @return void
	 */
	public function testSubmitMetadataRejectsWrongType(): void {
		$this->seedCertType();
		$secret = $this->makeSecret();
		$secret->setTypeId('type-login');
		$this->secretMapper->method('findById')->willReturn($secret);

		$this->expectException(InvalidArgumentException::class);
		$this->service->submitMetadata(secretId: 'secret-1', userId: 'alice', fields: []);
	}//end testSubmitMetadataRejectsWrongType()

	/**
	 * A valid submission upserts the metadata row and mirrors notAfter
	 * into the rotation-expiry per-secret path — the seam that leaves
	 * ciphertext and key_updated_at untouched (§6.2).
	 *
	 * @return void
	 */
	public function testSubmitMetadataSetsExpiryViaExpiryPath(): void {
		$this->seedCertType();
		$this->secretMapper->method('findById')->willReturn($this->makeSecret());
		$this->metadataMapper->method('findBySecretId')
			->willThrowException(new DoesNotExistException('none'));
		$this->metadataMapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->secretService->expects($this->once())->method('setExpiry')
			->with(
				$this->equalTo('secret-1'),
				$this->callback(static fn (DateTime $dt): bool => $dt->format('Y') === '2030'),
				$this->equalTo('alice')
			);

		$row = $this->service->submitMetadata(
			secretId: 'secret-1',
			userId: 'alice',
			fields: [
				'subject' => 'CN=stored.example',
				'notAfter' => '2030-01-01T00:00:00Z',
			],
		);

		$this->assertSame('CN=stored.example', $row->getSubject());
		$this->assertSame('alice', $row->getOwnerId());
	}//end testSubmitMetadataSetsExpiryViaExpiryPath()

	/**
	 * Unparseable client dates are rejected (§6.1 / D5 floor).
	 *
	 * @return void
	 */
	public function testSubmitMetadataRejectsGarbageDate(): void {
		$this->seedCertType();
		$this->secretMapper->method('findById')->willReturn($this->makeSecret());

		$this->expectException(InvalidArgumentException::class);
		$this->service->submitMetadata(secretId: 'secret-1', userId: 'alice', fields: ['notAfter' => 'not-a-date']);
	}//end testSubmitMetadataRejectsGarbageDate()

	/**
	 * Re-issue is owner/admin-scoped (§6.2).
	 *
	 * @return void
	 */
	public function testReissueSuiteRejectsNonOwner(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$this->suiteMapper->method('findById')->willReturn($suite);
		$this->issuanceService->expects($this->never())->method('reissueSuiteCertificate');

		$this->expectException(InvalidArgumentException::class);
		$this->service->reissueSuite(suiteId: 'suite-1', userId: 'mallory', isAdmin: false);
	}//end testReissueSuiteRejectsNonOwner()

	/**
	 * A re-sign that cannot preserve the original public key is
	 * surfaced as a conflict, never silently swallowed (§6.2 / D3).
	 *
	 * @return void
	 */
	public function testReissueSuiteRejectsKeyChangingResult(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$this->suiteMapper->method('findById')->willReturn($suite);
		$this->issuanceService->method('reissueSuiteCertificate')->willReturn(false);

		$this->expectException(\RuntimeException::class);
		$this->service->reissueSuite(suiteId: 'suite-1', userId: 'alice', isAdmin: false);
	}//end testReissueSuiteRejectsKeyChangingResult()

	/**
	 * A successful owner re-issue returns the refreshed server-parsed
	 * row (§6.2).
	 *
	 * @return void
	 */
	public function testReissueSuiteSucceedsForOwner(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setCertificate($this->pem);
		$this->suiteMapper->method('findById')->willReturn($suite);
		$this->issuanceService->expects($this->once())->method('reissueSuiteCertificate')->willReturn(true);

		$row = $this->service->reissueSuite(suiteId: 'suite-1', userId: 'alice', isAdmin: false);

		$this->assertSame('suite', $row['kind']);
		$this->assertSame('server_parsed', $row['metadataSource']);
	}//end testReissueSuiteSucceedsForOwner()

	/**
	 * The renewal checklist is honest: never renewable via the private
	 * CA, always the externally-issued guidance (§6.1 / D4).
	 *
	 * @return void
	 */
	public function testRenewalChecklistIsHonest(): void {
		$this->seedCertType();
		$this->secretMapper->method('findById')->willReturn($this->makeSecret());

		$checklist = $this->service->renewalChecklist(secretId: 'secret-1', userId: 'alice');

		$this->assertFalse($checklist['renewable']);
		$this->assertSame('externally_issued', $checklist['reason']);
		$this->assertNotEmpty($checklist['steps']);
	}//end testRenewalChecklistIsHonest()
}//end class
