<?php

/**
 * Database tests for the version lock on an application's write to its own
 * secret (#1269).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionAccessGuard;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareSaveRequiredGuard;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Runs against the instance's real database, inside a transaction that is
 * rolled back after each test.
 *
 * Two application writes read the same version of the row. The other one
 * commits first, through the same SecretService, between this write's read
 * and its lock. The database then answers this write's compare-and-set with
 * 0 rows, and the service retries on the row as it is now.
 *
 * @spec openspec/specs/secret-store-api/spec.md#requirement-conditional-machine-write-back
 */
class ApplicationWriteVersionLockTest extends TestCase {

	private const BASE = '2026-10-01T10:00:00+00:00';

	private const APP = 'app-lock-test';

	private ?IDBConnection $db = null;

	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		if ($this->db->tableExists('keepiq_share_save_stages') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		if ($this->db !== null && $this->db->inTransaction() === true) {
			$this->db->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * A SecretMapper that runs another write at a chosen read or lock of the
	 * write under test, and records what the lock answered that write. The
	 * other write's own reads and locks are not counted.
	 *
	 * @return SecretMapper
	 */
	private function racingMapper(): SecretMapper {
		return new class($this->db) extends SecretMapper {
			/**
			 * @var array<int,\Closure(): void> Run after the n-th read, by read number
			 */
			public array $afterRead = [];

			/**
			 * @var array<int,\Closure(): void> Run before the n-th lock, by lock number
			 */
			public array $beforeLock = [];

			/**
			 * @var list<int> What each lock of the write under test changed
			 */
			public array $locks = [];

			private int $reads = 0;

			private bool $racing = false;

			public function findById(string $id): Secret {
				$row = parent::findById($id);
				if ($this->racing === false) {
					$this->race(meanwhile: $this->afterRead[++$this->reads] ?? null);
				}

				return $row;
			}

			public function advanceVersion(string $id, DateTime $base, DateTime $savedAt): int {
				if ($this->racing === true) {
					return parent::advanceVersion(id: $id, base: $base, savedAt: $savedAt);
				}

				$this->race(meanwhile: $this->beforeLock[count($this->locks) + 1] ?? null);
				$changed = parent::advanceVersion(id: $id, base: $base, savedAt: $savedAt);
				$this->locks[] = $changed;
				return $changed;
			}

			private function race(?\Closure $meanwhile): void {
				if ($meanwhile === null) {
					return;
				}

				$this->racing = true;
				try {
					$meanwhile();
				} finally {
					$this->racing = false;
				}
			}
		};
	}

	private function service(SecretMapper $mapper): SecretService {
		$versions = new SecretVersionService(
			mapper: new SecretVersionMapper(db: $this->db),
			secretMapper: $mapper,
			accessGuard: $this->createMock(SecretVersionAccessGuard::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->db,
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			shareSaveRequired: $this->createStub(ShareSaveRequiredGuard::class),
		);

		return new SecretService(
			mapper: $mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->db,
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			shareSaveRequired: $this->createStub(ShareSaveRequiredGuard::class),
			versionService: $versions,
		);
	}

	private function insertApplicationSecret(SecretMapper $mapper): Secret {
		$secret = new Secret();
		$secret->setId(Uuid::uuid4()->toString());
		$secret->setName('Connector token');
		$secret->setTypeId('login');
		$secret->setKey('READ');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setOwnerType('application');
		$secret->setOwnerId(self::APP);
		$secret->setCreatedAt(new DateTime(self::BASE));
		$secret->setUpdatedAt(new DateTime(self::BASE));

		return $mapper->insert($secret);
	}

	/**
	 * The keys of a secret's versions, oldest first.
	 *
	 * @return list<string|null>
	 */
	private function versionKeys(string $secretId): array {
		$keys = array_map(
			static fn (SecretVersion $version): ?string => $version->getKey(),
			(new SecretVersionMapper(db: $this->db))->findBySecret(secretId: $secretId)
		);
		sort($keys);
		return $keys;
	}

	/**
	 * Another application write commits after this one read the row: this
	 * write's lock changes 0 rows, the retry on the row as it is now changes
	 * 1, the head is this write and the other write is kept as a version.
	 */
	public function testAWriteThatLosesToAnotherIsMadeOnTopOfIt(): void {
		$mapper = $this->racingMapper();
		$service = $this->service(mapper: $mapper);
		$id = $this->insertApplicationSecret(mapper: $mapper)->getId();
		$mapper->afterRead[1] = static function () use ($service, $id): void {
			$service->updateByApplication(id: $id, data: ['key' => 'MEANWHILE'], applicationId: self::APP);
		};

		$result = $service->updateByApplication(id: $id, data: ['key' => 'NEW'], applicationId: self::APP);

		$this->assertSame([0, 1], $mapper->locks);
		$this->assertSame('NEW', $result->getKey());
		$this->assertSame('NEW', $mapper->findById($id)->getKey());
		// READ was kept by the other write, MEANWHILE by this write's retry.
		$this->assertSame(['MEANWHILE', 'READ'], $this->versionKeys(secretId: $id));
	}

	/**
	 * The row moves again before the retry's lock: the write is refused
	 * (the 409 of the machine API) and leaves no head and no version.
	 *
	 * The second other write runs inside this write's transaction, since
	 * the test has one connection, so the refusal's rollback takes it back
	 * too. What it shows is the database's answer: 0 rows on both locks.
	 */
	public function testAWriteThatLosesTwiceIsRefusedAndWritesNothing(): void {
		$mapper = $this->racingMapper();
		$service = $this->service(mapper: $mapper);
		$id = $this->insertApplicationSecret(mapper: $mapper)->getId();
		$mapper->afterRead[1] = static function () use ($service, $id): void {
			$service->updateByApplication(id: $id, data: ['key' => 'MEANWHILE'], applicationId: self::APP);
		};
		$mapper->beforeLock[2] = static function () use ($service, $id): void {
			$service->updateByApplication(id: $id, data: ['key' => 'LATEST'], applicationId: self::APP);
		};

		try {
			$service->updateByApplication(id: $id, data: ['key' => 'NEW'], applicationId: self::APP);
			$this->fail('A write that loses its lock twice must be refused');
		} catch (StaleWriteException) {
		}

		$this->assertSame([0, 0], $mapper->locks);
		$this->assertNotSame('NEW', $mapper->findById($id)->getKey());
		// Only the first other write's snapshot of READ: this write kept none.
		$this->assertSame(['READ'], $this->versionKeys(secretId: $id));
	}
}
