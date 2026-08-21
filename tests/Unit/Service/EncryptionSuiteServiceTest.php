<?php

/**
 * Unit tests for EncryptionSuiteService.
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

use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\EncryptionSuiteProvisioningService;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EncryptionSuiteService.
 */
class EncryptionSuiteServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var EncryptionSuiteService
	 */
	private EncryptionSuiteService $service;

	/**
	 * The mocked suite mapper.
	 *
	 * @var EncryptionSuiteMapper
	 */
	private EncryptionSuiteMapper $mapper;

	/**
	 * The mocked CA service.
	 *
	 * @var CertificateAuthorityService
	 */
	private CertificateAuthorityService $caService;

	/**
	 * The mocked app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->caService = $this->createMock(originalClassName: CertificateAuthorityService::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$userManager = $this->createMock(originalClassName: \OCP\IUserManager::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new EncryptionSuiteService(
			mapper: $this->mapper,
			provisioning: new EncryptionSuiteProvisioningService(
				mapper: $this->mapper,
				caService: $this->caService,
				appConfig: $this->appConfig,
				userManager: $userManager,
				logger: $logger,
			),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Test that createSuite succeeds when the CA is healthy.
	 *
	 * @return void
	 */
	public function testCreateSuiteSuccess(): void {
		$this->appConfig->method('getValueString')->willReturn('healthy');
		$this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
		$this->mapper->expects($this->once())->method('insert');

		$suite = $this->service->createSuite('user', 'testuser', 'pubkey-pem', 'encrypted-pk');

		$this->assertEquals(expected: 'active', actual: $suite->getStatus());
		$this->assertEquals(expected: 'user', actual: $suite->getOwnerType());
		$this->assertEquals(expected: 'testuser', actual: $suite->getOwnerId());
	}//end testCreateSuiteSuccess()

	/**
	 * A plain create is refused when the owner already has an active suite.
	 *
	 * Issue #289. The endpoint checked auth, parameters and the migration write-lock,
	 * but never whether a suite already existed, so any authenticated session could
	 * mint a second `active` suite. Resolution selects the NEWEST active suite, so new
	 * secrets were sealed to a key the owner was not unlocking with — they decrypt for
	 * nobody, and nothing reports it at the time it happens.
	 *
	 * Asserts NO INSERT — nothing is persisted, which is the whole guarantee.
	 *
	 * It deliberately does NOT assert that signing is skipped. An earlier version of
	 * this fix checked state first so a refused create would spend no CA signature,
	 * and that ordering was wrong: a caller who both held a suite AND submitted
	 * malformed key material got 409, hiding a client error behind a state error. The
	 * Newman contract assertion "invalid public key is a 400 client error (not 500)"
	 * caught it. Signing now happens first, and the discarded certificate costs CPU
	 * only — no database write, and serials are random rather than a counter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCreateSuiteRefusesASecondActiveSuite(): void {
		$this->appConfig->method('getValueString')->willReturn('healthy');
		$this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
		$this->mapper->method('countActiveByOwner')->willReturn(1);

		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(exception: ConflictException::class);

		$this->service->createSuite('user', 'testuser', 'pubkey-pem', 'encrypted-pk');
	}//end testCreateSuiteRefusesASecondActiveSuite()

	/**
	 * Malformed key material reports as a CLIENT error even when a suite exists.
	 *
	 * The regression that the Newman contract suite caught and no unit test did: with
	 * the duplicate check running first, a caller who both held an active suite and
	 * submitted an unparseable public key received 409 instead of 400. Two things were
	 * wrong with the request and the response named the one the caller could do least
	 * about — "you already have a suite" tells them nothing about the garbage they
	 * sent, and a client fixing their key material would have had no signal at all.
	 *
	 * Pinned here so the ordering cannot quietly flip back: the exception type IS the
	 * contract, because the controller maps InvalidArgumentException to 400 and
	 * ConflictException to 409.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testAMalformedKeyIsAClientErrorEvenWhenASuiteAlreadyExists(): void {
		$this->appConfig->method('getValueString')->willReturn('healthy');
		$this->mapper->method('countActiveByOwner')->willReturn(1);
		$this->caService->method('signPublicKey')
			->willThrowException(new InvalidArgumentException('Invalid public key PEM'));

		$this->mapper->expects($this->never())->method('insert');

		// InvalidArgumentException -> 400, NOT ConflictException -> 409.
		$this->expectException(exception: InvalidArgumentException::class);

		$this->service->createSuite('user', 'testuser', 'not-a-pem', 'encrypted-pk');
	}//end testAMalformedKeyIsAClientErrorEvenWhenASuiteAlreadyExists()

	/**
	 * Compromise recovery still gets its successor while the old suite is active.
	 *
	 * The counterpart to the refusal above, and the reason the guard is a parameter
	 * rather than an unconditional rule: for the duration of a migration an owner
	 * legitimately holds two active suites, because the browser decrypts what it is
	 * migrating with the old one while the successor takes new writes. A guard without
	 * this opt-out would make key rotation impossible — which is also why the
	 * invariant must not be a database uniqueness constraint.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCompromiseRecoveryMayCreateASuccessorWhileOneIsActive(): void {
		$this->appConfig->method('getValueString')->willReturn('healthy');
		$this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
		// Never even asks: the successor path carries no single-active check, so the
		// count cannot accidentally start governing key rotation.
		$this->mapper->expects($this->never())->method('countActiveByOwner');

		$this->mapper->expects($this->once())->method('insert');

		$suite = $this->service->createSuccessorSuite(
			ownerType: 'user',
			ownerId: 'testuser',
			publicKeyPem: 'pubkey-pem',
			encryptedPrivateKey: 'encrypted-pk'
		);

		$this->assertEquals(expected: 'active', actual: $suite->getStatus());
	}//end testCompromiseRecoveryMayCreateASuccessorWhileOneIsActive()

	/**
	 * The owner check is scoped to the owner, not global.
	 *
	 * A guard that counted active suites across the whole instance would refuse every
	 * user after the first — the failure mode of a scoping bug here is a vault nobody
	 * new can set up, so the parameters are pinned rather than assumed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testTheActiveSuiteCheckIsScopedToTheOwner(): void {
		$this->appConfig->method('getValueString')->willReturn('healthy');
		$this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');

		$this->mapper->expects($this->once())
			->method('countActiveByOwner')
			->with('user', 'alice')
			->willReturn(0);
		$this->mapper->expects($this->once())->method('insert');

		$this->service->createSuite('user', 'alice', 'pubkey-pem', 'encrypted-pk');
	}//end testTheActiveSuiteCheckIsScopedToTheOwner()

	/**
	 * Test that createSuite throws when the CA is degraded.
	 *
	 * @return void
	 */
	public function testCreateSuiteFailsWhenCaDegraded(): void {
		$this->appConfig->method('getValueString')->willReturn('degraded');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/not healthy/');

		$this->service->createSuite('user', 'testuser', 'pubkey', 'pk');
	}//end testCreateSuiteFailsWhenCaDegraded()

	/**
	 * Test that revokeSuite marks the suite as revoked.
	 *
	 * @return void
	 */
	public function testRevokeSuiteSuccess(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');

		$this->mapper->method('findById')->willReturn($suite);
		$this->mapper->expects($this->once())->method('update');

		$result = $this->service->revokeSuite('suite-1', 'security concern', 'admin');

		$this->assertEquals(expected: 'revoked', actual: $result->getStatus());
		$this->assertEquals(expected: 'admin', actual: $result->getRevokedBy());
	}//end testRevokeSuiteSuccess()

	/**
	 * Test that revoking a compromised suite throws.
	 *
	 * @return void
	 */
	public function testRevokeCompromisedSuiteFails(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('compromised');

		$this->mapper->method('findById')->willReturn($suite);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->service->revokeSuite('suite-1', 'test', 'admin');
	}//end testRevokeCompromisedSuiteFails()

	/**
	 * Test that reinstateSuite reinstates a revoked suite.
	 *
	 * @return void
	 */
	public function testReinstateSuiteSuccess(): void {
		// Generate a real key pair for the test.
		$keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($keyPair, $privPem);

		// Create a self-signed cert.
		$csr = openssl_csr_new(['commonName' => 'Test'], $keyPair);
		$cert = openssl_csr_sign($csr, null, $keyPair, 365);
		openssl_x509_export($cert, $certPem);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('revoked');
		$suite->setCertificate($certPem);
		$suite->setRevokedAt(new \DateTime());
		$suite->setRevokedReason('test');
		$suite->setRevokedBy('admin');

		$this->mapper->method('findById')->willReturn($suite);
		$this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
		$this->mapper->expects($this->once())->method('update');

		$result = $this->service->reinstateSuite('suite-1', 'admin');

		$this->assertEquals(expected: 'active', actual: $result->getStatus());
		$this->assertNotNull(actual: $result->getReinstatedBy());
		// Revocation audit fields should be preserved.
		$this->assertNotNull(actual: $result->getRevokedAt());
	}//end testReinstateSuiteSuccess()

	/**
	 * Test that reinstating a compromised suite throws.
	 *
	 * @return void
	 */
	public function testReinstateCompromisedSuiteFails(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('compromised');

		$this->mapper->method('findById')->willReturn($suite);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->service->reinstateSuite('suite-1', 'admin');
	}//end testReinstateCompromisedSuiteFails()

	/**
	 * Test that reinstating an active suite throws.
	 *
	 * @return void
	 */
	public function testReinstateActiveSuiteFails(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');

		$this->mapper->method('findById')->willReturn($suite);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/Only revoked/');
		$this->service->reinstateSuite('suite-1', 'admin');
	}//end testReinstateActiveSuiteFails()

	/**
	 * Test that getSuite delegates to the mapper.
	 *
	 * @return void
	 */
	public function testGetSuiteDelegatesToMapper(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');

		$this->mapper->method('findById')
			->with('suite-1')
			->willReturn($suite);

		$result = $this->service->getSuite('suite-1');

		$this->assertSame(expected: $suite, actual: $result);
	}//end testGetSuiteDelegatesToMapper()

	/**
	 * Test that getActiveSuite delegates to the mapper.
	 *
	 * @return void
	 */
	public function testGetActiveSuiteDelegatesToMapper(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');

		$this->mapper->method('findActiveByOwner')
			->with('user', 'testuser')
			->willReturn($suite);

		$result = $this->service->getActiveSuite('user', 'testuser');

		$this->assertSame(expected: $suite, actual: $result);
	}//end testGetActiveSuiteDelegatesToMapper()

	/**
	 * Test that getSuitesByOwner delegates to the mapper.
	 *
	 * @return void
	 */
	public function testGetSuitesByOwnerDelegatesToMapper(): void {
		$suite1 = new EncryptionSuite();
		$suite1->setId('suite-1');
		$suite2 = new EncryptionSuite();
		$suite2->setId('suite-2');

		$this->mapper->method('findByOwner')
			->with('user', 'testuser')
			->willReturn([$suite1, $suite2]);

		$result = $this->service->getSuitesByOwner('user', 'testuser');

		$this->assertCount(expectedCount: 2, haystack: $result);
		$this->assertSame(expected: 'suite-1', actual: $result[0]->getId());
		$this->assertSame(expected: 'suite-2', actual: $result[1]->getId());
	}//end testGetSuitesByOwnerDelegatesToMapper()

	/**
	 * provisionForApplication extracts the public key from the CSR and
	 * keys the new suite to owner_type=application.
	 *
	 * @return void
	 */
	public function testProvisionForApplicationKeysSuiteToApplication(): void {
		$csr = (string)file_get_contents(__DIR__ . '/../fixtures/csr-4096.pem');

		$this->appConfig->method('getValueString')
			->with('doriath', 'ca_status', 'unknown')
			->willReturn('healthy');

		$this->caService->expects($this->once())
			->method('signPublicKey')
			->with($this->isType('string'), 'app-42')
			->willReturn('-----BEGIN CERTIFICATE-----STUB-----END CERTIFICATE-----');

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (EncryptionSuite $entity) use (&$captured) {
					$captured = $entity;
					return $entity;
				}
			);

		$result = $this->service->provisionForApplication(
			applicationId: 'app-42',
			csrPem: $csr,
		);

		$this->assertSame($captured, $result);
		$this->assertSame('application', $result->getOwnerType());
		$this->assertSame('app-42', $result->getOwnerId());
		$this->assertSame('', $result->getPrivateKey(), 'application suites store no private key');
		$this->assertSame('active', $result->getStatus());
	}//end testProvisionForApplicationKeysSuiteToApplication()

	/**
	 * provisionForApplication rejects a malformed CSR.
	 *
	 * @return void
	 */
	public function testProvisionForApplicationRejectsMalformedCsr(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not extract public key from CSR');
		$this->service->provisionForApplication(
			applicationId: 'app-42',
			csrPem: 'not-a-csr',
		);
	}//end testProvisionForApplicationRejectsMalformedCsr()

	/**
	 * provisionForApplication requires non-empty arguments.
	 *
	 * @return void
	 */
	public function testProvisionForApplicationRequiresApplicationId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('applicationId is required');
		$this->service->provisionForApplication(applicationId: '', csrPem: 'csr');
	}//end testProvisionForApplicationRequiresApplicationId()
}//end class
