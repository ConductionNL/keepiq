<?php

/**
 * MigrationWorkService page and transaction tests.
 *
 * Pins the work page listWork() answers (its shape, the limit and offset it
 * clamps and passes on, and the query order), and the outstanding counts
 * with recorded failures subtracted and clamped at zero.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\MigrationFailureMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\MigrationWorkService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the work page and the outstanding counts.
 */
class MigrationWorkPageTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var MigrationWorkService
	 */
	private MigrationWorkService $service;

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * @var SecretVersionMapper&MockObject
	 */
	private SecretVersionMapper&MockObject $versionMapper;

	/**
	 * @var AttachmentGrantMapper&MockObject
	 */
	private AttachmentGrantMapper&MockObject $grantMapper;

	/**
	 * @var MigrationFailureMapper&MockObject
	 */
	private MigrationFailureMapper&MockObject $failureMapper;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The reads made, in order.
	 *
	 * @var list<string>
	 */
	private array $reads = [];

	/**
	 * Wire the service over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->versionMapper = $this->createMock(SecretVersionMapper::class);
		$this->grantMapper = $this->createMock(AttachmentGrantMapper::class);
		$this->failureMapper = $this->createMock(MigrationFailureMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$this->service = new MigrationWorkService(
			secretMapper: $this->secretMapper,
			versionMapper: $this->versionMapper,
			grantMapper: $this->grantMapper,
			failureMapper: $this->failureMapper,
			db: $this->db,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			emergencyService: $this->createMock(EmergencyEnvelopeInvalidationService::class)
		);
	}//end setUp()

	/**
	 * A migration from old-suite to new-suite.
	 *
	 * @return SuiteMigration
	 */
	private function migration(): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');
		return $migration;
	}//end migration()

	/**
	 * Record a read and answer with the given value.
	 *
	 * @param string $read   The read's name and arguments
	 * @param mixed  $answer The value to answer
	 *
	 * @return mixed
	 */
	private function read(string $read, mixed $answer): mixed {
		$this->reads[] = $read;
		return $answer;
	}//end read()

	/**
	 * Stub every list and count read so it is recorded.
	 *
	 * @return void
	 */
	private function stubReads(): void {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Mail');
		$secret->setKey('K');
		$secret->setLogin('L');
		$secret->setAdditionalFields('F');
		$secret->setTotp('T');
		$secret->setPasskeys('P');
		$grant = new AttachmentGrant();
		$grant->setId('g-1');
		$grant->setSecretId('s-1');
		$grant->setWrappedFileKey('W');
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('s-1');
		$version->setVersionNumber(3);
		$version->setKey('VK');

		$this->secretMapper->method('findBySuiteForOwner')->willReturnCallback(
			fn (...$args) => $this->read(read: 'secrets '.json_encode($args), answer: [$secret])
		);
		$this->grantMapper->method('findBySuiteForRecipient')->willReturnCallback(
			fn (...$args) => $this->read(read: 'grants '.json_encode($args), answer: [$grant])
		);
		$this->versionMapper->method('findSecretIdsWithSuiteVersionsForOwner')->willReturnCallback(
			fn (...$args) => $this->read(read: 'versionSecrets '.json_encode($args), answer: ['s-1'])
		);
		$this->versionMapper->method('findBySecretAndSuite')->willReturnCallback(
			fn (...$args) => $this->read(read: 'versions '.json_encode($args), answer: [$version])
		);
		$this->secretMapper->method('countBySuiteForOwner')->willReturnCallback(
			fn (...$args) => $this->read(read: 'countSecrets '.json_encode($args), answer: 4)
		);
		$this->versionMapper->method('countBySuiteForOwner')->willReturnCallback(
			fn (...$args) => $this->read(read: 'countVersions '.json_encode($args), answer: 7)
		);
		$this->grantMapper->method('countBySuiteForRecipient')->willReturnCallback(
			fn (...$args) => $this->read(read: 'countGrants '.json_encode($args), answer: 2)
		);
		$this->failureMapper->method('countByMigrationAndStore')->willReturnCallback(
			fn (...$args) => $this->read(
				read: 'failed '.json_encode($args),
				answer: ['secrets' => 1, 'versions' => 9, 'attachmentGrants' => 2][$args[1]] ?? -1
			)
		);
	}//end stubReads()

	/**
	 * The page carries every section, in order, and the reads run in a fixed order.
	 *
	 * @return void
	 */
	public function testListWorkAnswersTheWholePageInOrder(): void {
		$this->stubReads();

		$page = $this->service->listWork(migration: $this->migration(), ownerId: 'alice', limit: 10, offset: 20);

		$this->assertSame(
			[
				'migrationId' => 'migration-1',
				'oldSuiteId' => 'old-suite',
				'newSuiteId' => 'new-suite',
				'limit' => 10,
				'offset' => 20,
				'versionWindow' => 5,
				'secrets' => [
					'records' => [['id' => 's-1', 'name' => 'Mail', 'key' => 'K', 'login' => 'L', 'additionalFields' => 'F', 'totp' => 'T', 'passkeys' => 'P']],
					'remaining' => 4,
				],
				'versions' => [
					'records' => [['id' => 'v-1', 'secretId' => 's-1', 'versionNumber' => 3, 'key' => 'VK', 'login' => null, 'additionalFields' => null, 'totp' => null, 'passkeys' => null]],
					'remaining' => 7,
					'dropCandidates' => 0,
				],
				'attachmentGrants' => [
					'records' => [['id' => 'g-1', 'secretId' => 's-1', 'wrappedFileKey' => 'W']],
					'remaining' => 2,
				],
				'totalRemaining' => 13,
			],
			$page
		);
		$this->assertSame(
			[
				'secrets ["old-suite","user","alice",10,20]',
				'grants ["old-suite","user","alice",10,20]',
				'versionSecrets ["old-suite","user","alice",10,20]',
				'versions ["s-1","old-suite"]',
				'countSecrets ["old-suite","user","alice"]',
				'countVersions ["old-suite","user","alice"]',
				'countGrants ["old-suite","user","alice"]',
				'failed ["migration-1","secrets"]',
				'failed ["migration-1","versions"]',
				'failed ["migration-1","attachmentGrants"]',
			],
			$this->reads
		);
	}//end testListWorkAnswersTheWholePageInOrder()

	/**
	 * The limit is clamped to 1..100 and the offset to 0 and up, in the page and the reads.
	 *
	 * @return void
	 */
	public function testListWorkClampsTheLimitAndOffset(): void {
		$this->stubReads();

		$low = $this->service->listWork(migration: $this->migration(), ownerId: 'alice', limit: 0, offset: -5);
		$high = $this->service->listWork(migration: $this->migration(), ownerId: 'alice', limit: 500);

		$this->assertSame([1, 0], [$low['limit'], $low['offset']]);
		$this->assertSame([100, 0], [$high['limit'], $high['offset']]);
		$this->assertSame('secrets ["old-suite","user","alice",1,0]', $this->reads[0]);
		$this->assertContains('secrets ["old-suite","user","alice",100,0]', $this->reads);
	}//end testListWorkClampsTheLimitAndOffset()

	/**
	 * Recorded failures are subtracted per store and clamped at zero.
	 *
	 * @return void
	 */
	public function testCountOutstandingSubtractsFailuresClampedAtZero(): void {
		$this->stubReads();

		$this->assertSame(
			[
				'secrets' => 4,
				'versions' => 7,
				'attachmentGrants' => 2,
				'total' => 13,
				'unaccountedSecrets' => 3,
				'unaccountedVersions' => 0,
				'unaccountedGrants' => 0,
				'unaccountedTotal' => 3,
				'failedTotal' => 12,
			],
			$this->service->countOutstanding(migration: $this->migration(), ownerId: 'alice')
		);
	}//end testCountOutstandingSubtractsFailuresClampedAtZero()
}//end class
