<?php

/**
 * Deleting an application removes its secrets, requests and suite (keepiq#753).
 *
 * Driven through ApplicationService::delete(), the caller, with the real
 * ApplicationDataCleanupService and SecretChildDataCleaner over mocked mappers.
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

use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Service\ApplicationDataCleanupService;
use OCA\Keepiq\Service\ApplicationService;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The delete cascade, through the service entry point.
 */
class ApplicationDeleteCascadeTest extends TestCase {

	/**
	 * Application mapper mock.
	 *
	 * @var ApplicationMapper&MockObject
	 */
	private ApplicationMapper $appMapper;

	/**
	 * Secret mapper mock.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secretMapper;

	/**
	 * Suite mapper mock.
	 *
	 * @var EncryptionSuiteMapper&MockObject
	 */
	private EncryptionSuiteMapper $suiteMapper;

	/**
	 * Request mapper mock.
	 *
	 * @var SecretRequestMapper&MockObject
	 */
	private SecretRequestMapper $requestMapper;

	/**
	 * Migration mapper mock.
	 *
	 * @var SuiteMigrationMapper&MockObject
	 */
	private SuiteMigrationMapper $migrationMapper;

	/**
	 * Connection mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * The service under test.
	 *
	 * @var ApplicationService
	 */
	private ApplicationService $service;

	/**
	 * Wire the service with the real cascade over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appMapper = $this->createMock(ApplicationMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->requestMapper = $this->createMock(SecretRequestMapper::class);
		$this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$application = new Application();
		$application->setId('app-1');
		$application->setName('Connector');
		$this->appMapper->method('findById')->willReturn($application);

		$this->service = new ApplicationService(
			mapper: $this->appMapper,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			dataCleanup: new ApplicationDataCleanupService(
				db: $this->db,
				secretMapper: $this->secretMapper,
				suiteMapper: $this->suiteMapper,
				requestMapper: $this->requestMapper,
				migrationMapper: $this->migrationMapper,
				childData: new SecretChildDataCleaner(secretMapper: $this->secretMapper),
			),
		);
	}//end setUp()

	/**
	 * The application's secrets, their requests, its own requests, its
	 * suite and the suite's migration records all go, in one transaction,
	 * before the application row.
	 *
	 * @return void
	 */
	public function testDeleteRemovesSecretsRequestsAndSuite(): void {
		$secret = new Secret();
		$secret->setId('sec-app');
		$this->secretMapper->method('findByOwner')
			->with('application', 'app-1')
			->willReturn([$secret]);

		$suite = new EncryptionSuite();
		$suite->setId('suite-app');
		$this->suiteMapper->method('findByOwner')->willReturn([$suite]);

		$order = [];
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->requestMapper->expects($this->once())->method('deleteBySecretId')->with('sec-app');
		$this->secretMapper->expects($this->once())
			->method('deleteByOwnerApplication')
			->with('app-1')
			->willReturnCallback(static function () use (&$order): int {
				$order[] = 'secrets';
				return 1;
			});
		$this->requestMapper->expects($this->once())
			->method('deleteByCreatedBy')
			->with('application:app-1')
			->willReturn(2);
		$this->migrationMapper->expects($this->once())->method('deleteBySuiteIds')->with(['suite-app']);
		$this->suiteMapper->expects($this->once())
			->method('deleteByOwnerApplication')
			->with('app-1')
			->willReturnCallback(static function () use (&$order): int {
				$order[] = 'suite';
				return 1;
			});
		$this->appMapper->expects($this->once())
			->method('delete')
			->willReturnCallback(static function (Application $app) use (&$order): Application {
				$order[] = 'application';
				return $app;
			});

		$this->service->delete(applicationId: 'app-1', isAdmin: true);

		$this->assertSame(['secrets', 'suite', 'application'], $order);
	}//end testDeleteRemovesSecretsRequestsAndSuite()

	/**
	 * A failing step rolls back and keeps the application row, so the
	 * admin can retry instead of being left with orphaned secrets.
	 *
	 * @return void
	 */
	public function testAFailedCascadeRollsBackAndKeepsTheApplication(): void {
		$this->secretMapper->method('findByOwner')->willReturn([]);
		$this->suiteMapper->method('findByOwner')->willReturn([]);
		$this->secretMapper->method('deleteByOwnerApplication')
			->willThrowException(new RuntimeException('database gone'));

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->appMapper->expects($this->never())->method('delete');

		$this->expectException(RuntimeException::class);
		$this->service->delete(applicationId: 'app-1', isAdmin: true);
	}//end testAFailedCascadeRollsBackAndKeepsTheApplication()
}//end class
