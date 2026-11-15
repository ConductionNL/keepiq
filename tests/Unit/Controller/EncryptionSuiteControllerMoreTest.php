<?php

/**
 * Unit tests for EncryptionSuiteController: the anonymous refusals and the
 * enrolment, force-revoke and compromise-recovery refusals.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\EncryptionSuiteController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Service\AdminSuiteRevocationService;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\TwoFactorGate;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every suite endpoint refuses an anonymous caller with a 401 before any
 * service is asked; enrolment refuses missing key material, a write lock and
 * malformed keys without registering a suite; a force-revoke refused as
 * malformed is a 400 and is audited; a compromise recovery whose successor
 * suite cannot be created for any reason other than a certificate mismatch
 * is a 500 and starts no migration.
 *
 * @covers \OCA\Keepiq\Controller\EncryptionSuiteController
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class EncryptionSuiteControllerMoreTest extends TestCase {

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
	 * The mocked administrator force-revoke.
	 *
	 * @var AdminSuiteRevocationService&MockObject
	 */
	private AdminSuiteRevocationService&MockObject $adminRevocation;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->suiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);
		$this->migrationService = $this->createMock(originalClassName: MigrationService::class);
		$this->proofService = $this->createMock(originalClassName: VaultKeyProofService::class);
		$this->adminRevocation = $this->createMock(originalClassName: AdminSuiteRevocationService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null). The
	 * two-factor policy does not block anyone.
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return EncryptionSuiteController
	 */
	private function controller(?string $uid = 'testuser'): EncryptionSuiteController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$twoFactor = $this->createMock(originalClassName: TwoFactorGate::class);
		$twoFactor->method('blocks')->willReturn(false);

		return new EncryptionSuiteController(
			request: $this->createMock(originalClassName: IRequest::class),
			suiteService: $this->suiteService,
			migrationService: $this->migrationService,
			userSession: $session,
			proofService: $this->proofService,
			emergencyService: $this->createMock(originalClassName: EmergencyEnvelopeInvalidationService::class),
			twoFactor: $twoFactor,
			adminRevocation: $this->adminRevocation,
		);
	}//end controller()

	/**
	 * Each endpoint, called anonymously.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function anonymousCalls(): array {
		return [
			'index' => [static fn (EncryptionSuiteController $c) => $c->index()],
			'show' => [static fn (EncryptionSuiteController $c) => $c->show(id: 'suite-1')],
			'create' => [static fn (EncryptionSuiteController $c) => $c->create(publicKey: 'PEM', encryptedPrivateKey: 'ENV')],
			'reenrol' => [static fn (EncryptionSuiteController $c) => $c->reenrol(publicKey: 'PEM', encryptedPrivateKey: 'ENV')],
			'revoke' => [static fn (EncryptionSuiteController $c) => $c->revoke(id: 'suite-1', reason: 'lost laptop')],
			'forceRevoke' => [static fn (EncryptionSuiteController $c) => $c->forceRevoke(id: 'suite-1', reason: 'left', confirmSuiteId: 'suite-1')],
			'compromiseRecovery' => [static fn (EncryptionSuiteController $c) => $c->compromiseRecovery(publicKey: 'PEM', encryptedPrivateKey: 'ENV')],
			'proofChallenge' => [
				static fn (EncryptionSuiteController $c) => $c->proofChallenge(id: 'suite-1', purpose: VaultKeyProofService::PURPOSE_REVOKE_SUITE),
			],
		];
	}//end anonymousCalls()

	/**
	 * An anonymous caller gets 401 and no service is asked anything.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @return void
	 *
	 * @dataProvider anonymousCalls
	 */
	public function testAnAnonymousCallerIsRefusedBeforeAnyService(callable $call): void {
		$this->suiteService->expects($this->never())->method($this->anything());
		$this->migrationService->expects($this->never())->method($this->anything());
		$this->proofService->expects($this->never())->method($this->anything());
		$this->adminRevocation->expects($this->never())->method($this->anything());

		$response = $call($this->controller(uid: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testAnAnonymousCallerIsRefusedBeforeAnyService()

	/**
	 * Key material that is missing or empty, on either enrolment route.
	 *
	 * @return array<string,array{0:string,1:string|null,2:string|null}>
	 */
	public static function missingKeyMaterial(): array {
		return [
			'create without a public key' => ['create', null, 'ENV'],
			'create with an empty public key' => ['create', '', 'ENV'],
			'reenrol without a private key' => ['reenrol', 'PEM', null],
			'reenrol with an empty private key' => ['reenrol', 'PEM', ''],
		];
	}//end missingKeyMaterial()

	/**
	 * Missing key material is a 400 naming both fields, and no suite is
	 * registered and no lock consulted.
	 *
	 * @param string      $route               The enrolment route.
	 * @param string|null $publicKey           The submitted public key.
	 * @param string|null $encryptedPrivateKey The submitted private-key envelope.
	 *
	 * @return void
	 *
	 * @dataProvider missingKeyMaterial
	 */
	public function testEnrolmentWithoutKeyMaterialIsABadRequest(string $route, ?string $publicKey, ?string $encryptedPrivateKey): void {
		$this->suiteService->expects($this->never())->method('createSuite');
		$this->migrationService->expects($this->never())->method('isWriteLocked');

		$response = $this->controller()->$route(publicKey: $publicKey, encryptedPrivateKey: $encryptedPrivateKey);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(
			expected: ['message' => 'Missing required parameters: publicKey and encryptedPrivateKey are required'],
			actual: $response->getData()
		);
	}//end testEnrolmentWithoutKeyMaterialIsABadRequest()

	/**
	 * While a key migration holds the caller's write lock no suite is
	 * registered: 403 with the reason.
	 *
	 * @return void
	 */
	public function testEnrolmentDuringAMigrationIsRefused(): void {
		$this->migrationService->expects($this->once())
			->method('isWriteLocked')
			->with('user', 'testuser')
			->willReturn(true);
		$this->suiteService->expects($this->never())->method('createSuite');

		$response = $this->controller()->create(publicKey: 'PEM', encryptedPrivateKey: 'ENV');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Write locked: an active key migration is in progress'], actual: $response->getData());
	}//end testEnrolmentDuringAMigrationIsRefused()

	/**
	 * Malformed key material the service rejects is a client error (400 with
	 * the service's reason), not a server fault.
	 *
	 * @return void
	 */
	public function testEnrolmentWithMalformedKeyMaterialIsABadRequest(): void {
		$this->suiteService->expects($this->once())
			->method('createSuite')
			->with('user', 'testuser', 'not a pem', 'ENV')
			->willThrowException(new InvalidArgumentException(message: 'publicKey is not a PEM public key'));

		$response = $this->controller()->reenrol(publicKey: 'not a pem', encryptedPrivateKey: 'ENV');

		$this->assertInstanceOf(expected: MessageResponse::class, actual: $response);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'publicKey is not a PEM public key'], actual: $response->getData());
	}//end testEnrolmentWithMalformedKeyMaterialIsABadRequest()

	/**
	 * A force-revoke the revocation service rejects as malformed is a 400 with
	 * the reason, and the refusal is recorded as invalid_argument.
	 *
	 * @return void
	 */
	public function testForceRevokeRejectedAsMalformedIsABadRequestAndAudited(): void {
		$this->adminRevocation->expects($this->once())
			->method('revoke')
			->with('suite-9', 'left the company', 'admin')
			->willThrowException(new InvalidArgumentException(message: 'The suite is already revoked'));
		$this->suiteService->expects($this->once())
			->method('recordRevokeRefused')
			->with('suite-9', 'admin', 'invalid_argument', false);

		$response = $this->controller(uid: 'admin')->forceRevoke(
			id: 'suite-9',
			reason: 'left the company',
			confirmSuiteId: 'suite-9'
		);

		$this->assertInstanceOf(expected: MessageResponse::class, actual: $response);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'The suite is already revoked'], actual: $response->getData());
	}//end testForceRevokeRejectedAsMalformedIsABadRequestAndAudited()

	/**
	 * A compromise recovery whose successor suite fails for a reason other
	 * than a certificate mismatch is a 500 with the reason, and no migration
	 * is started.
	 *
	 * @return void
	 */
	public function testCompromiseRecoveryWithAFailingSuccessorIsAServerError(): void {
		$this->migrationService->method('isWriteLocked')->with('user', 'testuser')->willReturn(false);
		$this->suiteService->method('getActiveSuite')
			->with('user', 'testuser')
			->willReturn($this->createMock(originalClassName: EncryptionSuite::class));
		$this->suiteService->expects($this->once())
			->method('createSuccessorSuite')
			->with('user', 'testuser', 'PEM', 'ENV')
			->willThrowException(new RuntimeException(message: 'Signing service unavailable'));
		$this->migrationService->expects($this->never())->method('initiateCompromiseRecovery');

		$response = $this->controller()->compromiseRecovery(publicKey: 'PEM', encryptedPrivateKey: 'ENV');

		$this->assertInstanceOf(expected: MessageResponse::class, actual: $response);
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Signing service unavailable'], actual: $response->getData());
	}//end testCompromiseRecoveryWithAFailingSuccessorIsAServerError()
}//end class
