<?php

/**
 * Unit tests for EncryptionSuiteController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\EncryptionSuiteController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\SuiteMigrationInProgressException;
use OCA\Keepiq\Service\CompromiseBlastRadius;
use OCA\Keepiq\Service\CompromiseContainmentService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for EncryptionSuiteController.
 */
class EncryptionSuiteControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var EncryptionSuiteController
	 */
	private EncryptionSuiteController $controller;

	/**
	 * The mocked suite service.
	 *
	 * @var EncryptionSuiteService&MockObject
	 */
	private EncryptionSuiteService&MockObject $suiteService;

	/**
	 * The mocked migration service.
	 *
	 * @var MigrationService&MockObject
	 */
	private MigrationService&MockObject $migrationService;

	/**
	 * The mocked vault-key-proof service.
	 *
	 * @var VaultKeyProofService&MockObject
	 */
	private VaultKeyProofService&MockObject $proofService;

	/**
	 * @var EmergencyEnvelopeInvalidationService&MockObject
	 */
	private EmergencyEnvelopeInvalidationService&MockObject $emergencyService;

	/**
	 * The mocked compromise containment.
	 *
	 * @var CompromiseContainmentService&MockObject
	 */
	private CompromiseContainmentService&MockObject $containment;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(originalClassName: IRequest::class);
		$this->suiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);
		$this->migrationService = $this->createMock(originalClassName: MigrationService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->proofService = $this->createMock(originalClassName: VaultKeyProofService::class);
		$this->emergencyService = $this->createMock(originalClassName: EmergencyEnvelopeInvalidationService::class);
		$this->containment = $this->createMock(originalClassName: CompromiseContainmentService::class);
		$this->containment->method('collect')->willReturn(new CompromiseBlastRadius());
		$this->containment->method('contain')->willReturn(['stamped' => 0, 'notified' => 0, 'failed' => 0]);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new EncryptionSuiteController(
			request: $request,
			suiteService: $this->suiteService,
			migrationService: $this->migrationService,
			userSession: $this->userSession,
			proofService: $this->proofService,
			emergencyService: $this->emergencyService,
			twoFactor: $this->twoFactorGate(),
			containment: $this->containment,
		);
	}//end setUp()

	/**
	 * Policy switch for the two-factor tests; off unless a test turns it on.
	 *
	 * @var bool
	 */
	private bool $twoFactorPolicy = false;

	/**
	 * The user's enabled two-factor providers.
	 *
	 * @var array<string,bool>
	 */
	private array $providers = [];

	/**
	 * A REAL TwoFactorGate over a mocked policy and registry.
	 *
	 * @return \OCA\Keepiq\Service\TwoFactorGate
	 */
	private function twoFactorGate(): \OCA\Keepiq\Service\TwoFactorGate {
		$policies = $this->createMock(\OCA\Keepiq\Service\VaultPolicyService::class);
		$policies->method('appliesTo')->willReturnCallback(fn (): bool => $this->twoFactorPolicy);
		$registry = $this->createMock(\OCP\Authentication\TwoFactorAuth\IRegistry::class);
		$registry->method('getProviderStates')->willReturnCallback(fn (): array => $this->providers);
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		return new \OCA\Keepiq\Service\TwoFactorGate(
			policies: $policies,
			registry: $registry,
			userManager: $userManager,
		);
	}//end twoFactorGate()

	/**
	 * Test index returns the current user's suites.
	 *
	 * @return void
	 */
	public function testIndexReturnsSuites(): void {
		$suite1 = new EncryptionSuite();
		$suite1->setId('suite-1');
		$suite1->setOwnerType('user');
		$suite1->setOwnerId('testuser');
		$suite1->setStatus('active');

		$suite2 = new EncryptionSuite();
		$suite2->setId('suite-2');
		$suite2->setOwnerType('user');
		$suite2->setOwnerId('testuser');
		$suite2->setStatus('revoked');

		$this->suiteService->method('getSuitesByOwner')
			->with('user', 'testuser')
			->willReturn([$suite1, $suite2]);

		$response = $this->controller->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertCount(expectedCount: 2, haystack: $data);
		$this->assertSame(expected: 'suite-1', actual: $data[0]['id']);
		$this->assertSame(expected: 'suite-2', actual: $data[1]['id']);
	}//end testIndexReturnsSuites()

	/**
	 * Test show returns a suite.
	 *
	 * @return void
	 */
	public function testShowReturnsSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($suite);

		$response = $this->controller->show('suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'suite-1', actual: $response->getData()['id']);
	}//end testShowReturnsSuite()

	/**
	 * Test show returns 404 when suite not found.
	 *
	 * @return void
	 */
	public function testShowReturns404WhenNotFound(): void {
		$this->suiteService->method('getSuite')
			->willThrowException(new DoesNotExistException('Not found'));

		$response = $this->controller->show('nonexistent');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testShowReturns404WhenNotFound()

	/**
	 * Test show returns 404 when suite belongs to another user.
	 *
	 * @return void
	 */
	public function testShowReturns404ForOtherUsersSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('otheruser');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($suite);

		$response = $this->controller->show('suite-1');

		// ValidateOwnership throws RuntimeException caught as a generic Exception -> NOT_FOUND.
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertArrayHasKey(key: 'message', array: $response->getData());
		$this->assertStringContainsString(needle: 'Access denied', haystack: $response->getData()['message']);
	}//end testShowReturns404ForOtherUsersSuite()

	/**
	 * Test create returns 201 on success.
	 *
	 * @return void
	 */
	public function testCreateReturns201OnSuccess(): void {
		$suite = new EncryptionSuite();
		$suite->setId('new-suite');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setStatus('active');

		$this->suiteService->method('createSuite')
			->with('user', 'testuser', 'pub-key-pem', 'encrypted-pk')
			->willReturn($suite);

		$response = $this->controller->create('pub-key-pem', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'new-suite', actual: $response->getData()['id']);
	}//end testCreateReturns201OnSuccess()

	/**
	 * Test create returns 503 when CA is degraded.
	 *
	 * @return void
	 */
	public function testCreateReturns503WhenCaDegraded(): void {
		$this->suiteService->method('createSuite')
			->willThrowException(new RuntimeException('CA is not healthy'));

		$response = $this->controller->create('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertArrayHasKey(key: 'message', array: $response->getData());
	}//end testCreateReturns503WhenCaDegraded()

	/**
	 * A duplicate-suite refusal is a 409, not the 503 its parent class would give.
	 *
	 * Issue #289. `ConflictException` extends `RuntimeException`, and this controller
	 * already maps `RuntimeException` to 503 — so if the catch arms are ever reordered,
	 * or the new arm removed, a duplicate create silently starts reporting "service
	 * unavailable". That tells the client to retry something it must never retry, and
	 * tells the operator a server is unhealthy when nothing is. The status code is the
	 * whole contract here, so it is pinned separately from the service-level test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCreateReturns409WhenAnActiveSuiteAlreadyExists(): void {
		$this->suiteService->method('createSuite')
			->willThrowException(new ConflictException('An active EncryptionSuite already exists for this owner.'));

		$response = $this->controller->create('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(
			expected: 'suite_already_exists',
			actual: $response->getData()['error'],
			message: 'the client needs a machine-readable reason, not only English prose'
		);
	}//end testCreateReturns409WhenAnActiveSuiteAlreadyExists()

	/**
	 * A plain create goes through the GUARDED entry point.
	 *
	 * Pinned by method choice: if this call site ever moved to createSuccessorSuite
	 * the guard would simply be off for the one path it exists to protect, and
	 * nothing else would look wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCreateUsesTheGuardedEntryPoint(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-new');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setStatus('active');

		$this->suiteService->expects($this->once())->method('createSuite')->willReturn($suite);
		$this->suiteService->expects($this->never())->method('createSuccessorSuite');

		$this->controller->create('pub-key', 'encrypted-pk');
	}//end testCreateUsesTheGuardedEntryPoint()

	/**
	 * Compromise recovery uses the SUCCESSOR entry point, not the guarded one.
	 *
	 * The more important half of the pair: routing recovery through the guarded
	 * createSuite() would refuse key rotation for anyone who has a suite — which is
	 * everyone who could need it — and nothing else would look wrong. Found by
	 * mutation: the earlier flag-based version of this call failed no test at all
	 * until this test existed.
	 *
	 * The old suite must stay active for the whole migration, so this path legitimately
	 * produces two active suites; see "Suite Resolution Is Deterministic During A
	 * Migration".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCompromiseRecoveryUsesTheSuccessorEntryPoint(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');
		$oldSuite->setPrivateKey('old-encrypted-pk');

		$newSuite = new EncryptionSuite();
		$newSuite->setId('new-suite');
		$newSuite->setStatus('active');

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->suiteService->method('getActiveSuite')->willReturn($oldSuite);
		$this->migrationService->method('initiateCompromiseRecovery')->willReturn($migration);

		$this->suiteService->expects($this->once())
			->method('createSuccessorSuite')
			->willReturn($newSuite);
		$this->suiteService->expects($this->never())->method('createSuite');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
	}//end testCompromiseRecoveryUsesTheSuccessorEntryPoint()

	/**
	 * Test updatePrivateKey returns updated suite.
	 *
	 * @return void
	 */
	public function testUpdatePrivateKeyReturnsSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setPrivateKey('old-pk');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($suite);

		$response = $this->controller->updatePrivateKey('suite-1', 'new-encrypted-pk');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'new-encrypted-pk', actual: $response->getData()['privateKey']);
	}//end testUpdatePrivateKeyReturnsSuite()

	/**
	 * Test revoke returns revoked suite.
	 *
	 * @return void
	 */
	public function testRevokeReturnsSuite(): void {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('revoked');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($owned);
		$this->suiteService->method('revokeSuite')
			->with('suite-1', 'security concern', 'testuser')
			->willReturn($suite);

		$response = $this->controller->revoke('suite-1', 'security concern');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
	}//end testRevokeReturnsSuite()

	/**
	 * Test revoke returns 400 for already compromised suite.
	 *
	 * @return void
	 */
	public function testRevokeReturns400ForCompromisedSuite(): void {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');

		$this->suiteService->method('getSuite')->willReturn($owned);
		$this->suiteService->method('revokeSuite')
			->willThrowException(new InvalidArgumentException('Cannot revoke a compromised suite'));

		$response = $this->controller->revoke('suite-1', 'test');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testRevokeReturns400ForCompromisedSuite()

	/**
	 * Revoke must refuse a suite owned by ANOTHER user, and must not reach the
	 * service at all.
	 *
	 * The `$user === null` preamble is an AUTHENTICATION check. Before this
	 * test existed, any authenticated user could revoke any suite by id, and
	 * revocation cascades a hard delete of the owner's ShareTargets plus a
	 * permanent promotion of their delegations.
	 *
	 * @return void
	 */
	public function testRevokeRefusesAnotherUsersSuiteAndNeverCallsTheService(): void {
		$foreign = new EncryptionSuite();
		$foreign->setId('suite-1');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('victim');
		$foreign->setStatus('active');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($foreign);
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->revoke('suite-1', 'security concern');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertStringContainsString(
			needle: 'Access denied',
			haystack: $response->getData()['message']
		);
	}//end testRevokeRefusesAnotherUsersSuiteAndNeverCallsTheService()

	/**
	 * Build an owned, active suite for the revoke-safeguard tests.
	 *
	 * @return EncryptionSuite
	 */
	private function ownedActiveSuite(): EncryptionSuite {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');
		$owned->setStatus('active');
		return $owned;
	}//end ownedActiveSuite()

	/**
	 * While a usable emergency contact exists, revocation without the override is
	 * refused with the COUNT — never the identities — and never reaches the
	 * destructive clear.
	 *
	 * @return void
	 */
	public function testRevokeRefusedWhileUsableEmergencyContactExists(): void {
		$this->suiteService->method('getSuite')->willReturn($this->ownedActiveSuite());
		$this->emergencyService->method('countUsableForGrantorSuite')->with('suite-1')->willReturn(2);
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->revoke('suite-1', 'lost password');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame('emergency_access_present', $data['error']);
		$this->assertSame(2, $data['usableEmergencyContacts']);
		// The count is surfaced; the contacts' identities are not.
		$this->assertArrayNotHasKey('granteeUserId', $data);
		$this->assertArrayNotHasKey('contacts', $data);
	}//end testRevokeRefusedWhileUsableEmergencyContactExists()

	/**
	 * With the explicit override, revocation proceeds and reaches the service
	 * even though a usable emergency contact exists.
	 *
	 * @return void
	 */
	public function testRevokeProceedsWithOverrideDespiteEmergencyContact(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');

		$this->suiteService->method('getSuite')->willReturn($this->ownedActiveSuite());
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(2);
		$this->suiteService->expects($this->once())
			->method('revokeSuite')
			->with('suite-1', 'lost password', 'testuser')
			->willReturn($revoked);

		$response = $this->controller->revoke('suite-1', 'lost password', acceptEmergencyLoss: true);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
	}//end testRevokeProceedsWithOverrideDespiteEmergencyContact()

	/**
	 * With no usable emergency contact the safeguard is inert and revocation
	 * proceeds unchanged, without an override.
	 *
	 * @return void
	 */
	public function testRevokeProceedsWhenNoUsableEmergencyContact(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');

		$this->suiteService->method('getSuite')->willReturn($this->ownedActiveSuite());
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(0);
		$this->suiteService->expects($this->once())->method('revokeSuite')->willReturn($revoked);

		$response = $this->controller->revoke('suite-1', 'housekeeping');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testRevokeProceedsWhenNoUsableEmergencyContact()

	/**
	 * Test revoke refuses an APPLICATION-owned suite and never calls the service.
	 *
	 * These are user self-service endpoints, so the only suite a session may act
	 * on is its own. The previous ownership guard only compared ids when
	 * `ownerType === 'user'`, so an application suite fell straight through it and
	 * any authenticated non-admin could revoke it by id — locking that
	 * application out of its own vault, since a revoked suite blocks every read.
	 * This is the same class of hole `testRevokeRefusesAnotherUsersSuiteAnd...`
	 * closes for user suites, one owner type over.
	 *
	 * @return void
	 */
	public function testRevokeRefusesAnApplicationSuiteAndNeverCallsTheService(): void {
		$appSuite = new EncryptionSuite();
		$appSuite->setId('suite-app-1');
		$appSuite->setOwnerType('application');
		$appSuite->setOwnerId('some-application');
		$appSuite->setStatus('active');

		$this->suiteService->method('getSuite')
			->with('suite-app-1')
			->willReturn($appSuite);
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->revoke('suite-app-1', 'attacker revokes an app they do not own');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertStringContainsString(
			needle: 'Access denied',
			haystack: $response->getData()['message']
		);
	}//end testRevokeRefusesAnApplicationSuiteAndNeverCallsTheService()

	/**
	 * Test updatePrivateKey refuses an APPLICATION-owned suite.
	 *
	 * The same guard protects the in-place envelope overwrite: without the fix a
	 * non-admin could write to an application suite's row through this endpoint.
	 *
	 * @return void
	 */
	public function testUpdatePrivateKeyRefusesAnApplicationSuite(): void {
		$appSuite = new EncryptionSuite();
		$appSuite->setId('suite-app-2');
		$appSuite->setOwnerType('application');
		$appSuite->setOwnerId('some-application');
		$appSuite->setStatus('active');

		$this->suiteService->method('getSuite')
			->with('suite-app-2')
			->willReturn($appSuite);
		$this->suiteService->expects($this->never())->method('updateSuite');

		$response = $this->controller->updatePrivateKey('suite-app-2', 'AAAA');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testUpdatePrivateKeyRefusesAnApplicationSuite()

	/**
	 * updatePrivateKey refuses a suite that is either end of an open migration
	 * (keepiq#869).
	 *
	 * Whoever holds a leaked old password can sign the proof with the old key, so
	 * without this a re-wrap of the old suite's envelope under a password only
	 * they know strands every record the owner has not migrated yet.
	 *
	 * @return void
	 */
	public function testUpdatePrivateKeyRefusesASuiteMidMigration(): void {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');
		$owned->setStatus('active');
		$this->suiteService->method('getSuite')->with('suite-1')->willReturn($owned);
		$this->migrationService->expects($this->once())
			->method('assertNoMigrationInProgress')
			->with('suite-1')
			->willThrowException(new SuiteMigrationInProgressException('mid-migration'));
		$this->suiteService->expects($this->never())->method('updateSuite');

		$response = $this->controller->updatePrivateKey('suite-1', 'attacker-envelope');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'migration_in_progress', actual: $response->getData()['error']);
	}//end testUpdatePrivateKeyRefusesASuiteMidMigration()

	/**
	 * updatePrivateKey refuses a suite that is not active (keepiq#869): a
	 * re-wrap of a revoked suite, followed by an admin reinstate, would hand the
	 * suite back under a password the owner does not know.
	 *
	 * @return void
	 */
	public function testUpdatePrivateKeyRefusesARevokedSuite(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setOwnerType('user');
		$revoked->setOwnerId('testuser');
		$revoked->setStatus('revoked');
		$this->suiteService->method('getSuite')->with('suite-1')->willReturn($revoked);
		$this->suiteService->expects($this->never())->method('updateSuite');

		$response = $this->controller->updatePrivateKey('suite-1', 'attacker-envelope');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'suite_not_active', actual: $response->getData()['error']);
	}//end testUpdatePrivateKeyRefusesARevokedSuite()

	/**
	 * Test reinstate returns reinstated suite.
	 *
	 * @return void
	 */
	public function testReinstateReturnsSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');

		$this->suiteService->method('reinstateSuite')
			->with('suite-1', 'testuser')
			->willReturn($suite);

		$response = $this->controller->reinstate('suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'active', actual: $response->getData()['status']);
	}//end testReinstateReturnsSuite()

	/**
	 * Test reinstate returns 400 when suite is not revoked.
	 *
	 * @return void
	 */
	public function testReinstateReturns400WhenNotRevoked(): void {
		$this->suiteService->method('reinstateSuite')
			->willThrowException(new InvalidArgumentException('Only revoked suites can be reinstated'));

		$response = $this->controller->reinstate('suite-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testReinstateReturns400WhenNotRevoked()

	/**
	 * Test compromiseRecovery creates new suite and migration.
	 *
	 * @return void
	 */
	public function testCompromiseRecoverySuccess(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');
		$oldSuite->setPrivateKey('old-encrypted-pk');

		$newSuite = new EncryptionSuite();
		$newSuite->setId('new-suite');
		$newSuite->setStatus('active');

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->suiteService->method('getActiveSuite')
			->with('user', 'testuser')
			->willReturn($oldSuite);
		$this->suiteService->method('createSuccessorSuite')
			->willReturn($newSuite);
		$this->migrationService->method('initiateCompromiseRecovery')
			->with('old-suite', 'new-suite')
			->willReturn($migration);

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 'new-suite', actual: $data['newSuite']['id']);
		$this->assertSame(expected: 'migr-1', actual: $data['migration']['id']);
		$this->assertSame(expected: 'old-encrypted-pk', actual: $data['oldEncryptedPrivateKey']);
	}//end testCompromiseRecoverySuccess()

	/**
	 * Test a second rotation is refused while one is still in progress.
	 *
	 * Without this guard, rotation 2 starts B→C while A→B is still open, and
	 * whatever was still on A becomes unreachable by any resume: resuming only
	 * ever walks its own migration's suite pair, so nothing will ever ask for
	 * the master password that opens A. The refusal must happen before any
	 * suite is created — a third suite is the damage.
	 *
	 * @return void
	 */
	public function testCompromiseRecoveryRefusedWhileMigrationInProgress(): void {
		$this->migrationService->method('isWriteLocked')
			->with('user', 'testuser')
			->willReturn(true);

		// Nothing may be created and no migration started.
		$this->suiteService->expects($this->never())->method('createSuite');
		$this->migrationService->expects($this->never())->method('initiateCompromiseRecovery');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'migration_in_progress', actual: $response->getData()['error']);
		// The message must point the user at the way out, not just say "no".
		$this->assertStringContainsString('Resume or abort', $response->getData()['message']);
	}//end testCompromiseRecoveryRefusedWhileMigrationInProgress()

	/**
	 * Test compromiseRecovery returns 500 on failure.
	 *
	 * @return void
	 */
	public function testCompromiseRecoveryReturns500OnFailure(): void {
		$this->suiteService->method('getActiveSuite')
			->willThrowException(new DoesNotExistException('No active suite'));

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
	}//end testCompromiseRecoveryReturns500OnFailure()

	/**
	 * Test a certificate that does not carry the submitted key aborts with a
	 * DISTINCT error, not a generic fault.
	 *
	 * The distinction is the point: nothing was written, so the user's vault is
	 * provably untouched and retrying is safe — whereas a 500 tells them nothing
	 * and invites a support ticket. If this ever regressed to a generic error the
	 * user would be told their vault might be damaged when it is not.
	 *
	 * This also pins a coupling that is easy to break from a distance. The
	 * controller recognises the condition by matching a SUBSTRING of the message
	 * CertificateIssuanceService throws. Rewording that message upstream — which
	 * has already happened once, when the signing body was extracted out of
	 * CertificateAuthorityService — silently downgrades this to a 500 with no
	 * test failing anywhere. Asserting it here makes that rewording loud.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-refuses-to-start-on-an-unusable-new-suite
	 */
	public function testCompromiseRecoveryReportsCertificateKeyMismatchDistinctly(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');

		$this->suiteService->method('getActiveSuite')->willReturn($oldSuite);

		// Verbatim from CertificateIssuanceService, which throws when the issued
		// certificate does not carry the key that was submitted for it.
		$this->suiteService->method('createSuccessorSuite')->willThrowException(
			new \RuntimeException(
				'Refusing to issue a certificate that does not carry the submitted public key'
			)
		);

		// The vault must be left alone: no migration, so no write lock either.
		$this->migrationService->expects($this->never())->method('initiateCompromiseRecovery');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');
		$data = $response->getData();

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'certificate_key_mismatch', actual: $data['error']);
		// And the message must tell the user their data is safe to retry.
		$this->assertStringContainsString('vault is unchanged', $data['message']);
	}//end testCompromiseRecoveryReportsCertificateKeyMismatchDistinctly()

	/**
	 * Test compromiseRecovery defers all terminal work to migration completion.
	 *
	 * Every outstanding link share signed against the now-compromised public
	 * key must be invalidated so a holder cannot decrypt the snapshot after
	 * the user reports the breach. The cascade fires *after* markCompromised
	 * (so the old suite is already locked) and *before* createSuite (so the
	 * new suite never receives leaked references).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#5.2
	 */
	public function testCompromiseRecoveryLeavesTerminalWorkToCompletion(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');
		$oldSuite->setPrivateKey('old-encrypted-pk');

		$newSuite = new EncryptionSuite();
		$newSuite->setId('new-suite');
		$newSuite->setStatus('active');

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->suiteService->method('getActiveSuite')
			->with('user', 'testuser')
			->willReturn($oldSuite);
		$this->suiteService->method('createSuccessorSuite')
			->willReturn($newSuite);
		$this->migrationService->method('initiateCompromiseRecovery')
			->willReturn($migration);

		// The controller must NOT perform any terminal work. Marking the old
		// suite compromised (or revoking its link shares) before the migration
		// has run is what locked the user out of their whole vault: every read
		// then threw SuiteBlockedException, including the reads the migration
		// itself depends on. All of it now happens in
		// MigrationService::completeMigration once every store is migrated.
		$this->suiteService->expects($this->never())->method('markCompromised');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
	}//end testCompromiseRecoveryLeavesTerminalWorkToCompletion()

	/**
	 * proofChallenge issues a challenge for a valid purpose on an owned suite.
	 *
	 * @return void
	 */
	public function testProofChallengeIssuesForAValidPurpose(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$this->suiteService->method('getSuite')->with('suite-1')->willReturn($suite);

		$this->proofService->method('issueChallenge')
			->with('testuser', VaultKeyProofService::PURPOSE_COMPROMISE_RECOVERY)
			->willReturn(['nonce' => 'n.mac', 'expiresAt' => 123]);

		$response = $this->controller->proofChallenge('suite-1', VaultKeyProofService::PURPOSE_COMPROMISE_RECOVERY);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'n.mac', actual: $response->getData()['nonce']);
	}//end testProofChallengeIssuesForAValidPurpose()

	/**
	 * proofChallenge rejects a missing or unknown purpose with 400 and never
	 * issues a challenge.
	 *
	 * @return void
	 */
	public function testProofChallengeRejectsAnUnknownPurpose(): void {
		$this->proofService->expects($this->never())->method('issueChallenge');

		$response = $this->controller->proofChallenge('suite-1', 'not-a-real-purpose');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testProofChallengeRejectsAnUnknownPurpose()

	/**
	 * proofChallenge refuses to issue against a suite the caller does not own.
	 *
	 * @return void
	 */
	public function testProofChallengeRefusesAForeignSuite(): void {
		$foreign = new EncryptionSuite();
		$foreign->setId('suite-1');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('someoneelse');
		$this->suiteService->method('getSuite')->with('suite-1')->willReturn($foreign);
		$this->proofService->expects($this->never())->method('issueChallenge');

		$response = $this->controller->proofChallenge('suite-1', VaultKeyProofService::PURPOSE_UPDATE_PRIVATE_KEY);

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testProofChallengeRefusesAForeignSuite()

	/**
	 * forceRevoke is guarded by the admin setting AND Nextcloud sudo, and carries
	 * NO vault-key proof — an administrator holds no vault key (ADR-005). This
	 * reflection backstop keeps the guard posture from being loosened silently;
	 * the middleware itself (a real 401/403 for a non-administrator or a stale
	 * sudo window) needs a running instance, exactly as VaultKeyProofAttributesTest
	 * documents for its own destructive-route coverage.
	 *
	 * @return void
	 */
	public function testForceRevokeCarriesAdminAndSudoGuardsButNoVaultProof(): void {
		$method = new \ReflectionMethod(EncryptionSuiteController::class, 'forceRevoke');

		$this->assertCount(
			expectedCount: 1,
			haystack: $method->getAttributes(\OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting::class),
			message: 'forceRevoke must be admin-guarded, mirroring reinstate()'
		);
		$this->assertCount(
			expectedCount: 1,
			haystack: $method->getAttributes(\OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired::class),
			message: 'forceRevoke must require Nextcloud sudo'
		);
		// A vault-key proof is unproducible by an administrator, so the owner
		// path's guard must NOT be present here.
		$this->assertCount(
			expectedCount: 0,
			haystack: $method->getAttributes(\OCA\Keepiq\Attribute\VaultKeyProofRequired::class),
			message: 'forceRevoke must not carry a vault-key proof — the administrator holds no vault key'
		);
	}//end testForceRevokeCarriesAdminAndSudoGuardsButNoVaultProof()

	/**
	 * An empty (or whitespace-only) reason is rejected with 400 and the suite is
	 * never revoked.
	 *
	 * @return void
	 */
	public function testForceRevokeRejectsAnEmptyReason(): void {
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->forceRevoke('suite-1', '   ', confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testForceRevokeRejectsAnEmptyReason()

	/**
	 * Data for the typed-confirmation refusals (keepiq#871).
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function badConfirmationProvider(): array {
		return [
			'missing'        => [''],
			'another suite'  => ['suite-2'],
			'a prefix'       => ['suite-'],
			'different case' => ['SUITE-1'],
		];
	}//end badConfirmationProvider()

	/**
	 * A force-revoke whose typed confirmation is missing or is not the route's
	 * suite id is refused with 400 before anything happens: no migration check,
	 * no emergency count, no revoke, only the refusal audit (keepiq#871). This is
	 * the guard that holds where sudo mode is skipped (SSO backends).
	 *
	 * @param string $confirmSuiteId The typed confirmation
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badConfirmationProvider')]
	public function testForceRevokeRefusesAMissingOrWrongTypedConfirmation(string $confirmSuiteId): void {
		$this->suiteService->expects($this->never())->method('revokeSuite');
		$this->migrationService->expects($this->never())->method('assertNoMigrationInProgress');
		$this->suiteService->expects($this->once())
			->method('recordRevokeRefused')
			->with('suite-1', 'testuser', 'confirmation_mismatch', true);

		$response = $this->controller->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: $confirmSuiteId);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'confirmation_mismatch', actual: $response->getData()['error']);
	}//end testForceRevokeRefusesAMissingOrWrongTypedConfirmation()

	/**
	 * A refused force-revoke reaches the audit trail with a fixed reason code,
	 * so an attack on the containment path is visible (keepiq#870).
	 *
	 * @return void
	 */
	public function testForceRevokeRecordsAnEmptyReasonRefusal(): void {
		$this->suiteService->expects($this->once())
			->method('recordRevokeRefused')
			->with('suite-1', 'testuser', 'empty_reason', true);

		$this->controller->forceRevoke('suite-1', '   ', markCompromised: true, confirmSuiteId: 'suite-1');
	}//end testForceRevokeRecordsAnEmptyReasonRefusal()

	/**
	 * A force-revoke refused because the suite is in a migration is audited as
	 * migration_in_progress, never with the exception message (keepiq#870).
	 *
	 * @return void
	 */
	public function testForceRevokeRecordsAMigrationInProgressRefusal(): void {
		$this->migrationService->method('assertNoMigrationInProgress')
			->willThrowException(new \OCA\Keepiq\Exception\SuiteMigrationInProgressException('suite-1 is migrating'));
		$this->suiteService->expects($this->once())
			->method('recordRevokeRefused')
			->with('suite-1', 'testuser', 'migration_in_progress', false);

		$response = $this->controller->forceRevoke('suite-1', 'routine', confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
	}//end testForceRevokeRecordsAMigrationInProgressRefusal()

	/**
	 * An application-owned suite is force-revoked by the same endpoint, with the
	 * administrator recorded as revokedBy and no ownership check or vault-key
	 * proof — the whole point is a cross-owner admin action (ADR-005).
	 *
	 * @return void
	 */
	public function testForceRevokeRevokesAnApplicationSuiteAsAdmin(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('00000000-0000-0000-0000-000000000000');
		$revoked->setOwnerType('application');
		$revoked->setStatus('revoked');

		// Cross-owner: getSuite()/validateOwnership() must NOT be consulted.
		$this->suiteService->expects($this->never())->method('getSuite');
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(0);
		$this->suiteService->expects($this->once())
			->method('revokeSuite')
			->with(
				'00000000-0000-0000-0000-000000000000',
				'application retired',
				'testuser',
				false,
				0
			)
			->willReturn($revoked);

		$response = $this->controller->forceRevoke(
			'00000000-0000-0000-0000-000000000000',
			'application retired',
			confirmSuiteId: '00000000-0000-0000-0000-000000000000'
		);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
	}//end testForceRevokeRevokesAnApplicationSuiteAsAdmin()

	/**
	 * The usable-emergency-contact count is read BEFORE the revoke cascade,
	 * threaded into revokeSuite() and surfaced in the response as a count only —
	 * never the contacts' identities, and never as a gate.
	 *
	 * @return void
	 */
	public function testForceRevokeReadsEmergencyCountBeforeCascadeAndSurfacesIt(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');

		$this->emergencyService->expects($this->once())
			->method('countUsableForGrantorSuite')
			->with('suite-1')
			->willReturn(3);
		// The count is passed through to the service (audit) as the 5th arg, and
		// the revoke is NOT gated on it (unlike the owner path).
		$this->suiteService->expects($this->once())
			->method('revokeSuite')
			->with('suite-1', 'compromise', 'testuser', true, 3)
			->willReturn($revoked);

		$response = $this->controller->forceRevoke('suite-1', 'compromise', markCompromised: true, confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 3, actual: $data['emergencyContactsDestroyed']);
		// Only the count crosses the wire.
		$this->assertArrayNotHasKey('granteeUserId', $data);
		$this->assertArrayNotHasKey('contacts', $data);
		// markCompromised=true drives the cascade, so no rotation warning.
		$this->assertArrayNotHasKey('warning', $data);
	}//end testForceRevokeReadsEmergencyCountBeforeCascadeAndSurfacesIt()

	/**
	 * When markCompromised is left off, no cascade runs and the response carries
	 * the "user may still know these secrets" rotation warning for the UI.
	 *
	 * @return void
	 */
	public function testForceRevokeWithoutCompromiseReturnsTheRotationWarning(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');

		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(0);
		$this->suiteService->method('revokeSuite')->willReturn($revoked);

		$response = $this->controller->forceRevoke('suite-1', 'de-authorised departure', confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertArrayHasKey('warning', $response->getData());
		$this->assertStringContainsString(
			needle: 'may still know these secrets',
			haystack: $response->getData()['warning']
		);
	}//end testForceRevokeWithoutCompromiseReturnsTheRotationWarning()

	/**
	 * Force-revoke refuses a suite that is part of an in-progress migration,
	 * before anything is touched (keepiq#803).
	 *
	 * @return void
	 */
	public function testForceRevokeRefusesASuiteMidMigration(): void {
		$this->migrationService->expects($this->once())
			->method('assertNoMigrationInProgress')
			->with('suite-1')
			->willThrowException(new SuiteMigrationInProgressException('mid-migration'));
		$this->emergencyService->expects($this->never())->method('countUsableForGrantorSuite');
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->forceRevoke('suite-1', 'departed', confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'migration_in_progress', actual: $response->getData()['error']);
	}//end testForceRevokeRefusesASuiteMidMigration()

	/**
	 * The owner's own revoke has the same hazard and the same refusal.
	 *
	 * @return void
	 */
	public function testOwnerRevokeRefusesASuiteMidMigration(): void {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');
		$this->suiteService->method('getSuite')->willReturn($owned);
		$this->migrationService->expects($this->once())
			->method('assertNoMigrationInProgress')
			->with('suite-1')
			->willThrowException(new SuiteMigrationInProgressException('mid-migration'));
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->revoke('suite-1', 'security concern', true);

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'migration_in_progress', actual: $response->getData()['error']);
	}//end testOwnerRevokeRefusesASuiteMidMigration()

	/**
	 * An in-progress migration between suite-1 (old) and suite-2 (new).
	 *
	 * @return SuiteMigration
	 */
	private function openMigration(): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('suite-1');
		$migration->setNewSuiteId('suite-2');
		$migration->setStatus('in_progress');

		return $migration;
	}//end openMigration()

	/**
	 * Record revokeSuite() and terminateForCompromise() calls in order.
	 *
	 * @param array<int,string> $log  Receives "revoke:<id>" and "terminate:<id>"
	 * @param string|null       $fail A suite id whose revoke throws, once
	 *
	 * @return void
	 */
	private function recordCompromiseCalls(array &$log, ?string $fail = null): void {
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(0);
		$this->suiteService->method('revokeSuite')->willReturnCallback(
			static function (string $id) use (&$log, &$fail): EncryptionSuite {
				if ($id === $fail) {
					$fail = null;
					throw new RuntimeException('database went away');
				}

				$log[] = 'revoke:' . $id;
				$suite = new EncryptionSuite();
				$suite->setId($id);
				$suite->setStatus('revoked');
				return $suite;
			}
		);
		$this->migrationService->method('terminateForCompromise')->willReturnCallback(
			static function (SuiteMigration $migration) use (&$log): void {
				$log[] = 'terminate:' . $migration->getId();
			}
		);
	}//end recordCompromiseCalls()

	/**
	 * A compromise force-revoke is not blocked by an in-progress migration: it
	 * revokes BOTH ends, and only then ends the migration (keepiq#809 review).
	 *
	 * @return void
	 */
	public function testCompromiseForceRevokeEndsTheMigrationAndRevokesBothEnds(): void {
		$this->migrationService->expects($this->never())->method('assertNoMigrationInProgress');
		$this->migrationService->method('findInProgressForSuite')->with('suite-1')->willReturn($this->openMigration());
		$log = [];
		$this->recordCompromiseCalls($log);

		$response = $this->controller->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(['revoke:suite-1', 'revoke:suite-2', 'terminate:migration-1'], $log);
		$this->assertSame('migration-1', $response->getData()['terminatedMigration']);
		$this->assertSame('suite-2', $response->getData()['alsoRevokedSuite']);
	}//end testCompromiseForceRevokeEndsTheMigrationAndRevokesBothEnds()

	/**
	 * Force-revoking the NEW end revokes the old end as well (#809 review). In
	 * the attack this guards against the new end is the attacker's suite, so it
	 * is the one an admin is likely to pick.
	 *
	 * @return void
	 */
	public function testCompromiseForceRevokeOfTheNewEndAlsoRevokesTheOldEnd(): void {
		$this->migrationService->method('findInProgressForSuite')->with('suite-2')->willReturn($this->openMigration());
		$log = [];
		$this->recordCompromiseCalls($log);

		$response = $this->controller->forceRevoke('suite-2', 'account taken over', true, confirmSuiteId: 'suite-2');

		$this->assertSame(['revoke:suite-2', 'revoke:suite-1', 'terminate:migration-1'], $log);
		$this->assertSame('suite-1', $response->getData()['alsoRevokedSuite']);
	}//end testCompromiseForceRevokeOfTheNewEndAlsoRevokesTheOldEnd()

	/**
	 * If revoking the other end fails, the migration is left open, so a retry
	 * still finds it and finishes the job (#809 review). Terminating first would
	 * leave the other end live with nothing pointing back to it.
	 *
	 * @return void
	 */
	public function testAFailedOtherEndRevokeCanBeRetried(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn($this->openMigration());
		$log = [];
		$this->recordCompromiseCalls($log, 'suite-2');

		$first = $this->controller->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');
		$this->assertNotSame(Http::STATUS_OK, $first->getStatus());
		$this->assertNotContains('terminate:migration-1', $log, 'nothing may be terminated while the other end is live');

		$retry = $this->controller->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');
		$this->assertSame(Http::STATUS_OK, $retry->getStatus());
		$this->assertContains('revoke:suite-2', $log);
		$this->assertSame('terminate:migration-1', end($log));
	}//end testAFailedOtherEndRevokeCanBeRetried()

	/**
	 * A compromise force-revoke with no migration revokes just the one suite.
	 *
	 * @return void
	 */
	public function testCompromiseForceRevokeWithoutAMigrationRevokesOneSuite(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn(null);
		$this->migrationService->expects($this->never())->method('terminateForCompromise');
		$log = [];
		$this->recordCompromiseCalls($log);

		$response = $this->controller->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(['revoke:suite-1'], $log);
		$this->assertArrayNotHasKey('terminatedMigration', $response->getData());
	}//end testCompromiseForceRevokeWithoutAMigrationRevokesOneSuite()

	/**
	 * A suite with a wrapped private key, owned by the session user.
	 *
	 * @return EncryptionSuite
	 */
	private function keyedSuite(): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setStatus('active');
		$suite->setCertificate('CERT');
		$suite->setPrivateKey('WRAPPED-KEY');
		return $suite;
	}//end keyedSuite()

	/**
	 * admin-vault-policies §3.1: with the policy on and only backup codes
	 * enabled, the list and the single suite carry no private key and say why.
	 *
	 * @return void
	 */
	public function testTwoFactorPolicyWithholdsThePrivateKey(): void {
		$this->twoFactorPolicy = true;
		$this->providers = ['backup_codes' => true, 'totp' => false];
		$this->suiteService->method('getSuitesByOwner')->willReturn([$this->keyedSuite()]);
		$this->suiteService->method('getSuite')->willReturn($this->keyedSuite());

		foreach ([$this->controller->index()->getData()[0], $this->controller->show('suite-1')->getData()] as $data) {
			$this->assertArrayNotHasKey('privateKey', $data);
			$this->assertSame('two_factor_required', $data['unlockBlocked']);
			$this->assertSame('CERT', $data['certificate']);
		}
	}//end testTwoFactorPolicyWithholdsThePrivateKey()

	/**
	 * Scenario "Enabling two-factor login restores access": an enabled TOTP
	 * provider gives the key back, with no administrator action.
	 *
	 * @return void
	 */
	public function testAnEnabledProviderRestoresThePrivateKey(): void {
		$this->twoFactorPolicy = true;
		$this->providers = ['backup_codes' => true, 'totp' => true];
		$this->suiteService->method('getSuitesByOwner')->willReturn([$this->keyedSuite()]);

		$data = $this->controller->index()->getData()[0];

		$this->assertSame('WRAPPED-KEY', $data['privateKey']);
		$this->assertArrayNotHasKey('unlockBlocked', $data);
	}//end testAnEnabledProviderRestoresThePrivateKey()

	/**
	 * Without the policy nothing changes, even with no provider at all.
	 *
	 * @return void
	 */
	public function testPolicyOffKeepsThePrivateKey(): void {
		$this->suiteService->method('getSuitesByOwner')->willReturn([$this->keyedSuite()]);

		$this->assertSame('WRAPPED-KEY', $this->controller->index()->getData()[0]['privateKey']);
	}//end testPolicyOffKeepsThePrivateKey()

	/**
	 * §3.2: no first suite while the policy blocks the user.
	 *
	 * @return void
	 */
	public function testTwoFactorPolicyRefusesAFirstSuite(): void {
		$this->twoFactorPolicy = true;
		$this->suiteService->expects($this->never())->method('createSuite');

		$response = $this->controller->create(publicKey: 'PEM', encryptedPrivateKey: 'ENVELOPE');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('two_factor_required', $response->getData()['code']);
	}//end testTwoFactorPolicyRefusesAFirstSuite()

	/**
	 * The blast radius of BOTH ends is collected before either end is revoked:
	 * each revoke's cascade deletes the ShareTargets the lookup reads, so a
	 * lookup after the first revoke missed the source owners of the copies on
	 * the second suite (keepiq#864).
	 *
	 * @return void
	 */
	public function testCompromiseCollectsBothEndsBeforeAnyRevoke(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn($this->openMigration());
		$log = [];
		$this->recordCompromiseCalls($log);
		$containment = $this->createMock(CompromiseContainmentService::class);
		$containment->expects($this->once())
			->method('collect')
			->with(['suite-1', 'suite-2'])
			->willReturnCallback(
				static function () use (&$log): CompromiseBlastRadius {
					$log[] = 'collect';
					return new CompromiseBlastRadius();
				}
			);
		$containment->method('contain')->willReturnCallback(
			static function () use (&$log): array {
				$log[] = 'contain';
				return ['stamped' => 0, 'notified' => 0, 'failed' => 0];
			}
		);

		$response = $this->controllerWith(containment: $containment)->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['collect', 'revoke:suite-1', 'revoke:suite-2', 'terminate:migration-1', 'contain'], $log);
	}//end testCompromiseCollectsBothEndsBeforeAnyRevoke()

	/**
	 * A containment step that failed reaches the administrator in the response,
	 * not only the server log (keepiq#863).
	 *
	 * @return void
	 */
	public function testAnIncompleteCascadeReachesTheResponse(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn(null);
		$log = [];
		$this->recordCompromiseCalls($log);
		$containment = $this->createMock(CompromiseContainmentService::class);
		$containment->method('collect')->willReturn(new CompromiseBlastRadius());
		$containment->method('contain')->willReturn(['stamped' => 4, 'notified' => 1, 'failed' => 2]);

		$response = $this->controllerWith(containment: $containment)->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$data = $response->getData();
		$this->assertTrue($data['cascadeIncomplete']);
		$this->assertSame(['stamped' => 4, 'notified' => 1, 'failed' => 2], $data['cascade']);
	}//end testAnIncompleteCascadeReachesTheResponse()

	/**
	 * When revoking the other end throws, the named suite is already revoked:
	 * its containment still runs on the radius collected before any revoke,
	 * and is audited, while the error still reaches the administrator. A
	 * retry would collect after the revoke and miss the source owners
	 * (keepiq#864, keepiq#1189).
	 *
	 * @return void
	 */
	public function testAFailedOtherEndRevokeStillContains(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn($this->openMigration());
		$log = [];
		$this->recordCompromiseCalls($log, 'suite-2');
		$radius = new CompromiseBlastRadius();
		$tally = ['stamped' => 3, 'notified' => 2, 'failed' => 0];
		$containment = $this->createMock(CompromiseContainmentService::class);
		$containment->method('collect')->willReturn($radius);
		$containment->expects($this->once())
			->method('contain')
			->with($radius, $this->callback(static fn (EncryptionSuite $suite): bool => $suite->getId() === 'suite-1'), 'testuser')
			->willReturn($tally);
		$containment->expects($this->once())->method('notifyEmergencyAccessCleared');
		$this->suiteService->expects($this->once())
			->method('recordContainment')
			->with('suite-1', 'testuser', $tally);

		$response = $this->controllerWith(containment: $containment)->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$this->assertNotSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotContains('terminate:migration-1', $log, 'nothing may be terminated while the other end is live');
	}//end testAFailedOtherEndRevokeStillContains()

	/**
	 * The containment tally reaches the audit trail, not only the response
	 * (keepiq#1189).
	 *
	 * @return void
	 */
	public function testTheContainmentIsAudited(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn(null);
		$log = [];
		$this->recordCompromiseCalls($log);
		$containment = $this->createMock(CompromiseContainmentService::class);
		$containment->method('collect')->willReturn(new CompromiseBlastRadius());
		$containment->method('contain')->willReturn(['stamped' => 4, 'notified' => 1, 'failed' => 2]);
		$this->suiteService->expects($this->once())
			->method('recordContainment')
			->with('suite-1', 'testuser', ['stamped' => 4, 'notified' => 1, 'failed' => 2]);

		$response = $this->controllerWith(containment: $containment)->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testTheContainmentIsAudited()

	/**
	 * A complete cascade says so.
	 *
	 * @return void
	 */
	public function testACompleteCascadeIsNotFlagged(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn(null);
		$log = [];
		$this->recordCompromiseCalls($log);

		$response = $this->controller->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1');

		$this->assertFalse($response->getData()['cascadeIncomplete']);
	}//end testACompleteCascadeIsNotFlagged()

	/**
	 * The second suite's destroyed emergency contacts are returned too, and the
	 * owner is told about both ends' contacts in one notice (keepiq#876, #877).
	 *
	 * @return void
	 */
	public function testTheSecondSuitesEmergencyCountIsReturnedAndTheOwnerTold(): void {
		$this->migrationService->method('findInProgressForSuite')->willReturn($this->openMigration());
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturnMap([['suite-1', 2], ['suite-2', 1]]);
		$this->suiteService->method('revokeSuite')->willReturnCallback(
			static function (string $id): EncryptionSuite {
				$suite = new EncryptionSuite();
				$suite->setId($id);
				$suite->setOwnerType('user');
				$suite->setOwnerId('alice');
				$suite->setStatus('revoked');
				return $suite;
			}
		);
		$containment = $this->createMock(CompromiseContainmentService::class);
		$containment->method('collect')->willReturn(new CompromiseBlastRadius());
		$containment->method('contain')->willReturn(['stamped' => 0, 'notified' => 0, 'failed' => 0]);
		$containment->expects($this->once())
			->method('notifyEmergencyAccessCleared')
			->with($this->callback(static fn (EncryptionSuite $suite): bool => $suite->getId() === 'suite-1'), 3);

		$data = $this->controllerWith(containment: $containment)->forceRevoke('suite-1', 'account taken over', true, confirmSuiteId: 'suite-1')->getData();

		$this->assertSame(2, $data['emergencyContactsDestroyed']);
		$this->assertSame(1, $data['alsoRevokedEmergencyContactsDestroyed']);
		$this->assertSame('suite-2', $data['alsoRevokedSuite']);
	}//end testTheSecondSuitesEmergencyCountIsReturnedAndTheOwnerTold()

	/**
	 * A plain force-revoke runs no containment but still tells the owner their
	 * emergency contacts are gone (keepiq#876).
	 *
	 * @return void
	 */
	public function testAPlainForceRevokeTellsTheOwnerButRunsNoContainment(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setOwnerType('user');
		$revoked->setOwnerId('alice');
		$revoked->setStatus('revoked');
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(2);
		$this->suiteService->method('revokeSuite')->willReturn($revoked);
		$containment = $this->createMock(CompromiseContainmentService::class);
		$containment->expects($this->never())->method('collect');
		$containment->expects($this->never())->method('contain');
		$containment->expects($this->once())->method('notifyEmergencyAccessCleared')->with($revoked, 2);

		$response = $this->controllerWith(containment: $containment)->forceRevoke('suite-1', 'departed', confirmSuiteId: 'suite-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAPlainForceRevokeTellsTheOwnerButRunsNoContainment()

	/**
	 * A controller sharing this test's mocks but with its own containment.
	 *
	 * @param CompromiseContainmentService $containment The containment double
	 *
	 * @return EncryptionSuiteController
	 */
	private function controllerWith(CompromiseContainmentService $containment): EncryptionSuiteController {
		return new EncryptionSuiteController(
			request: $this->createMock(originalClassName: IRequest::class),
			suiteService: $this->suiteService,
			migrationService: $this->migrationService,
			userSession: $this->userSession,
			proofService: $this->proofService,
			emergencyService: $this->emergencyService,
			twoFactor: $this->twoFactorGate(),
			containment: $containment,
		);
	}//end controllerWith()

	/**
	 * A user whose suite was revoked and who has no active suite cannot enrol a
	 * new one with only a session: a stolen session would otherwise replace the
	 * victim's identity right after the containment (keepiq#860).
	 *
	 * @return void
	 */
	public function testCreateIsRefusedAfterARevocationWithoutAFreshConfirmation(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');
		$this->suiteService->method('getSuitesByOwner')->willReturn([$revoked]);
		$this->suiteService->expects($this->never())->method('createSuite');

		$response = $this->controller->create('-----BEGIN PUBLIC KEY-----', 'envelope');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('reauthentication_required', $response->getData()['error']);
	}//end testCreateIsRefusedAfterARevocationWithoutAFreshConfirmation()

	/**
	 * A user with an active suite next to a replaced one is not sent to the
	 * re-enrol route; createSuite() answers with its own conflict.
	 *
	 * @return void
	 */
	public function testCreateIsNotGatedWhenAnActiveSuiteExists(): void {
		$old = new EncryptionSuite();
		$old->setStatus('compromised');
		$active = new EncryptionSuite();
		$active->setStatus('active');
		$this->suiteService->method('getSuitesByOwner')->willReturn([$old, $active]);
		$this->suiteService->method('createSuite')->willThrowException(new ConflictException('already'));

		$response = $this->controller->create('-----BEGIN PUBLIC KEY-----', 'envelope');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testCreateIsNotGatedWhenAnActiveSuiteExists()

	/**
	 * reenrol() carries Nextcloud sudo, and enrols the user once it passes.
	 *
	 * @return void
	 */
	public function testReenrolRequiresSudoAndEnrols(): void {
		$method = new \ReflectionMethod(EncryptionSuiteController::class, 'reenrol');
		$this->assertCount(1, $method->getAttributes(\OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired::class));
		$this->assertCount(1, $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class));

		$created = new EncryptionSuite();
		$created->setId('suite-2');
		$created->setStatus('active');
		$this->suiteService->expects($this->never())->method('getSuitesByOwner');
		$this->suiteService->expects($this->once())
			->method('createSuite')
			->with('user', 'testuser', '-----BEGIN PUBLIC KEY-----', 'envelope')
			->willReturn($created);

		$response = $this->controller->reenrol('-----BEGIN PUBLIC KEY-----', 'envelope');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testReenrolRequiresSudoAndEnrols()

	/**
	 * reinstate() carries Nextcloud sudo, like force-revoke: it is the
	 * dangerous direction (keepiq#865).
	 *
	 * @return void
	 */
	public function testReinstateRequiresSudo(): void {
		$method = new \ReflectionMethod(EncryptionSuiteController::class, 'reinstate');

		$this->assertCount(1, $method->getAttributes(\OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting::class));
		$this->assertCount(
			expectedCount: 1,
			haystack: $method->getAttributes(\OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired::class),
			message: 'reinstate must require Nextcloud sudo'
		);
	}//end testReinstateRequiresSudo()

	/**
	 * A refused reinstate reaches the administrator as a 409 with its reason.
	 *
	 * @return void
	 */
	public function testAReinstateOfACompromiseRevokeIsRefusedWith409(): void {
		$this->suiteService->method('reinstateSuite')->willThrowException(
			new \OCA\Keepiq\Exception\ReinstateRefusedException('revoked_as_compromised', 'compromised')
		);

		$response = $this->controller->reinstate('suite-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('revoked_as_compromised', $response->getData()['error']);
	}//end testAReinstateOfACompromiseRevokeIsRefusedWith409()

}//end class
