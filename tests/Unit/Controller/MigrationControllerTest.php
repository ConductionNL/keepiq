<?php

/**
 * Unit tests for MigrationController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\MigrationController;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MigrationController.
 */
class MigrationControllerTest extends TestCase {

	private MigrationController $controller;

	private MigrationService&MockObject $migrationService;

	private EncryptionSuiteService&MockObject $suiteService;

	private IUserSession&MockObject $userSession;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new MigrationController(
			$request,
			$this->migrationService,
			$this->suiteService,
			$this->userSession,
		);
	}//end setUp()

	/**
	 * Test getStatus returns migration when in progress.
	 *
	 * @return void
	 */
	public function testGetStatusReturnsMigration(): void {
		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->migrationService->method('getInProgressMigration')
			->with('user', 'testuser')
			->willReturn($migration);

		$response = $this->controller->getStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('migr-1', $response->getData()['id']);
		$this->assertSame('in_progress', $response->getData()['status']);
	}//end testGetStatusReturnsMigration()

	/**
	 * Test getStatus returns 'none' when no migration in progress.
	 *
	 * @return void
	 */
	public function testGetStatusReturnsNoneWhenNoMigration(): void {
		$this->migrationService->method('getInProgressMigration')
			->willThrowException(new DoesNotExistException('No migration'));

		$response = $this->controller->getStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('none', $response->getData()['status']);
	}//end testGetStatusReturnsNoneWhenNoMigration()

	/**
	 * Build an EncryptionSuite owned by 'testuser' for ownership-check stubs.
	 *
	 * @return EncryptionSuite
	 */
	private function makeOwnedSuite(): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		return $suite;
	}//end makeOwnedSuite()

	/**
	 * Build a SuiteMigration with the given id and old suite reference.
	 *
	 * @param string $id Migration id
	 * @param string $oldSuiteId Id of the old suite
	 *
	 * @return SuiteMigration
	 */
	private function makeMigration(string $id, string $oldSuiteId = 'suite-1'): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId($id);
		$migration->setOldSuiteId($oldSuiteId);
		return $migration;
	}//end makeMigration()

	/**
	 * Test complete delegates and returns migration.
	 *
	 * @return void
	 */
	public function testCompleteReturnsMigration(): void {
		$pending = $this->makeMigration('migr-1');
		$this->migrationService->method('getMigration')
			->with('migr-1')
			->willReturn($pending);

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($this->makeOwnedSuite());

		$completed = new SuiteMigration();
		$completed->setId('migr-1');
		$completed->setStatus('completed');

		$this->migrationService->method('completeMigration')
			->with('migr-1', false)
			->willReturn($completed);

		$response = $this->controller->complete('migr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('completed', $response->getData()['status']);
	}//end testCompleteReturnsMigration()

	/**
	 * Test complete with errors.
	 *
	 * @return void
	 */
	public function testCompleteWithErrors(): void {
		$pending = $this->makeMigration('migr-1');
		$this->migrationService->method('getMigration')
			->with('migr-1')
			->willReturn($pending);

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($this->makeOwnedSuite());

		$completed = new SuiteMigration();
		$completed->setId('migr-1');
		$completed->setStatus('completed_with_errors');

		$this->migrationService->method('completeMigration')
			->with('migr-1', true)
			->willReturn($completed);

		$response = $this->controller->complete('migr-1', true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('completed_with_errors', $response->getData()['status']);
	}//end testCompleteWithErrors()

	/**
	 * Test complete returns 400 on failure.
	 *
	 * @return void
	 */
	public function testCompleteReturns400OnFailure(): void {
		$this->migrationService->method('getMigration')
			->willThrowException(new DoesNotExistException('Migration not found'));

		$response = $this->controller->complete('nonexistent');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCompleteReturns400OnFailure()
}//end class
