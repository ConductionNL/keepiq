<?php

/**
 * Unit tests for the per-record endpoints of MigrationController.
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

use OCA\Keepiq\Controller\MigrationController;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\MigrationWorkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the guard order, refusals and response bodies of the three
 * per-record re-encryption endpoints.
 */
class MigrationControllerRecordTest extends TestCase {

	private MigrationService&MockObject $migrationService;

	private MigrationWorkService&MockObject $workService;

	private EncryptionSuiteService&MockObject $suiteService;

	private IUserSession&MockObject $userSession;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->migrationService = $this->createMock(MigrationService::class);
		$this->workService = $this->createMock(MigrationWorkService::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return MigrationController
	 */
	private function controller(): MigrationController {
		return new MigrationController(
			request: $this->createMock(IRequest::class),
			migrationService: $this->migrationService,
			workService: $this->workService,
			suiteService: $this->suiteService,
			envelopeService: $this->createMock(EmergencyEnvelopeInvalidationService::class),
			userSession: $this->userSession,
		);
	}//end controller()

	/**
	 * Sign in as the test user.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Stub a migration of the test user in the given status.
	 *
	 * @param string $status The migration status
	 *
	 * @return void
	 */
	private function arrangeOwnMigration(string $status = 'in_progress'): void {
		$this->signIn();

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('suite-1');
		$migration->setNewSuiteId('suite-2');
		$migration->setStatus($status);
		$this->migrationService->method('getMigration')->willReturn($migration);

		$suite = new EncryptionSuite();
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$this->suiteService->method('getSuite')->willReturn($suite);
	}//end arrangeOwnMigration()

	/**
	 * Call each of the three per-record endpoints with a complete commit.
	 *
	 * @return array<string,JSONResponse>
	 */
	private function callAll(): array {
		$controller = $this->controller();

		return [
			'secret' => $controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW'),
			'version' => $controller->reEncryptVersion('migr-1', 'version-1', key: 'NEW'),
			'grant' => $controller->reEncryptAttachmentGrant('migr-1', 'grant-1', wrappedFileKey: 'NEW'),
		];
	}//end callAll()

	/**
	 * Without a session user every endpoint answers 401 before any lookup.
	 *
	 * @return void
	 */
	public function testUnauthenticatedIsRefusedBeforeLookup(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->migrationService->expects($this->never())->method('getMigration');

		foreach ($this->callAll() as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['message' => 'Unauthorized'], $response->getData());
		}
	}//end testUnauthenticatedIsRefusedBeforeLookup()

	/**
	 * A missing migration answers 404 and nothing is committed.
	 *
	 * @return void
	 */
	public function testMissingMigrationIsNotFound(): void {
		$this->signIn();
		$this->migrationService->method('getMigration')->willThrowException(new DoesNotExistException('gone'));
		$this->workService->expects($this->never())->method('recordFailure');

		foreach ($this->callAll() as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['message' => 'Migration not found'], $response->getData());
		}
	}//end testMissingMigrationIsNotFound()

	/**
	 * A foreign migration answers 403 with the ownership message.
	 *
	 * @return void
	 */
	public function testForeignMigrationIsForbidden(): void {
		$this->signIn();

		$migration = new SuiteMigration();
		$migration->setOldSuiteId('suite-1');
		$migration->setStatus('in_progress');
		$this->migrationService->method('getMigration')->willReturn($migration);

		$suite = new EncryptionSuite();
		$suite->setOwnerType('user');
		$suite->setOwnerId('00000000-0000-0000-0000-000000000000');
		$this->suiteService->method('getSuite')->willReturn($suite);

		foreach ($this->callAll() as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => 'Forbidden: migration does not belong to you'], $response->getData());
		}
	}//end testForeignMigrationIsForbidden()

	/**
	 * A failure from the migration lookup itself is not turned into a response.
	 *
	 * @return void
	 */
	public function testLookupFailureIsNotCaught(): void {
		$this->signIn();
		$this->migrationService->method('getMigration')->willThrowException(new RuntimeException('database down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('database down');

		$this->controller()->reEncryptVersion('migr-1', 'version-1', key: 'NEW');
	}//end testLookupFailureIsNotCaught()

	/**
	 * A terminated migration answers 409 on every endpoint.
	 *
	 * @return void
	 */
	public function testTerminatedMigrationIsConflict(): void {
		$this->arrangeOwnMigration(status: 'aborted');
		$this->workService->expects($this->never())->method('recordFailure');

		foreach ($this->callAll() as $response) {
			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
			$this->assertSame(['message' => 'Migration is no longer in progress'], $response->getData());
		}
	}//end testTerminatedMigrationIsConflict()

	/**
	 * A commit without its ciphertext is refused with the store's message.
	 *
	 * @return void
	 */
	public function testMissingCiphertextIsForbidden(): void {
		$this->arrangeOwnMigration();
		$controller = $this->controller();

		$expected = [
			['A re-encrypted key ciphertext is required', $controller->reEncryptSecret('migr-1', 'secret-1')],
			['A re-encrypted key ciphertext is required', $controller->reEncryptVersion('migr-1', 'version-1')],
			['A re-wrapped file key is required', $controller->reEncryptAttachmentGrant('migr-1', 'grant-1')],
		];

		foreach ($expected as [$message, $response]) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => $message], $response->getData());
		}
	}//end testMissingCiphertextIsForbidden()

	/**
	 * A reported failure is recorded per store and echoed back.
	 *
	 * @return void
	 */
	public function testReportedFailureIsRecordedPerStore(): void {
		$this->arrangeOwnMigration();
		$this->workService->expects($this->never())->method('commitSecret');
		$this->workService->expects($this->never())->method('commitAttachmentGrant');
		$this->workService->expects($this->exactly(2))
			->method('recordFailure')
			->willReturnCallback(
				static fn (SuiteMigration $migration, string $ownerId, string $store, string $recordId, string $message): string => "{$store}:{$recordId}:{$ownerId}:{$message}"
			);

		$controller = $this->controller();
		$secret = $controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW', error: 'bad');
		$grant = $controller->reEncryptAttachmentGrant('migr-1', 'grant-1', error: 'worse');

		$this->assertSame(
			['recorded' => true, 'store' => 'secrets', 'recordId' => 'secret-1', 'secretId' => 'secrets:secret-1:testuser:bad'],
			$secret->getData()
		);
		$this->assertSame(
			['recorded' => true, 'store' => 'attachmentGrants', 'recordId' => 'grant-1', 'secretId' => 'attachmentGrants:grant-1:testuser:worse'],
			$grant->getData()
		);
	}//end testReportedFailureIsRecordedPerStore()

	/**
	 * Each committed store answers with its identifiers only.
	 *
	 * @return void
	 */
	public function testCommittedRecordsAnswerWithIdentifiers(): void {
		$this->arrangeOwnMigration();

		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setEncryptionSuiteId('suite-2');
		$this->workService->expects($this->once())->method('commitSecret')
			->with($this->isInstanceOf(SuiteMigration::class), 'testuser', 'secret-1', 'NEW', 'LOGIN', 'MORE', null, null)
			->willReturn($secret);

		$version = new SecretVersion();
		$version->setId('version-1');
		$version->setSecretId('secret-1');
		$version->setEncryptionSuiteId('suite-2');
		$this->workService->expects($this->once())->method('commitVersion')
			->with($this->isInstanceOf(SuiteMigration::class), 'testuser', 'version-1', 'NEW', null, 'MORE', 'TOTP', null)
			->willReturn($version);

		$grant = new AttachmentGrant();
		$grant->setId('grant-1');
		$grant->setSecretId('secret-1');
		$grant->setEncryptionSuiteId('suite-2');
		$this->workService->expects($this->once())->method('commitAttachmentGrant')
			->with($this->isInstanceOf(SuiteMigration::class), 'testuser', 'grant-1', 'WRAPPED')
			->willReturn($grant);

		$controller = $this->controller();

		$this->assertSame(
			['id' => 'secret-1', 'encryptionSuiteId' => 'suite-2', 'possiblyCompromisedAt' => null],
			$controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW', login: 'LOGIN', additionalFields: 'MORE')->getData()
		);
		$this->assertSame(
			['id' => 'version-1', 'secretId' => 'secret-1', 'encryptionSuiteId' => 'suite-2'],
			$controller->reEncryptVersion('migr-1', 'version-1', key: 'NEW', additionalFields: 'MORE', totp: 'TOTP')->getData()
		);
		$this->assertSame(
			['id' => 'grant-1', 'secretId' => 'secret-1', 'encryptionSuiteId' => 'suite-2'],
			$controller->reEncryptAttachmentGrant('migr-1', 'grant-1', wrappedFileKey: 'WRAPPED')->getData()
		);
	}//end testCommittedRecordsAnswerWithIdentifiers()

	/**
	 * Any other failure of a commit answers 400 with its message.
	 *
	 * @return void
	 */
	public function testOtherCommitFailureIsBadRequest(): void {
		$this->arrangeOwnMigration();
		$this->workService->method('commitAttachmentGrant')->willThrowException(new RuntimeException('write failed'));

		$response = $this->controller()->reEncryptAttachmentGrant('migr-1', 'grant-1', wrappedFileKey: 'NEW');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'write failed'], $response->getData());
	}//end testOtherCommitFailureIsBadRequest()
}//end class
