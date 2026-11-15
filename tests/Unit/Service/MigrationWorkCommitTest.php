<?php

/**
 * MigrationWorkService commit transaction tests.
 *
 * Pins that a secret or version commit writes every re-sealed field and the
 * new suite in one committed transaction, and that a failing commit rolls
 * its transaction back and rethrows.
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
 * Tests the commit transactions.
 */
class MigrationWorkCommitTest extends TestCase {

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
	 * A commit whose write fails rolls its transaction back and rethrows.
	 *
	 * @return void
	 */
	public function testAFailingCommitRollsBack(): void {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setEncryptionSuiteId('old-suite');
		$secret->setKey('OLD');
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willThrowException(new \RuntimeException('write failed'));
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('s-1');
		$version->setEncryptionSuiteId('old-suite');
		$version->setKey('OLD');
		$this->versionMapper->method('findById')->willReturn($version);
		$this->versionMapper->method('update')->willThrowException(new \RuntimeException('version write failed'));
		$this->db->expects($this->exactly(2))->method('beginTransaction');
		$this->db->expects($this->exactly(2))->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->commitSecret(migration: $this->migration(), ownerId: 'alice', secretId: 's-1', key: 'NEW', login: null, additionalFields: null);
			$this->fail('The failed write was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('write failed', $e->getMessage());
		}

		try {
			$this->service->commitVersion(migration: $this->migration(), ownerId: 'alice', versionId: 'v-1', key: 'NEW', login: null, additionalFields: null);
			$this->fail('The failed version write was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('version write failed', $e->getMessage());
		}
	}//end testAFailingCommitRollsBack()

	/**
	 * A committed secret and version take every re-sealed field and the new suite,
	 * inside one committed transaction each.
	 *
	 * @return void
	 */
	public function testACommitWritesEveryResealedField(): void {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setEncryptionSuiteId('old-suite');
		$secret->setKey('OLD');
		$secret->setTotp('OLD-T');
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willReturnArgument(0);
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId('s-1');
		$version->setEncryptionSuiteId('old-suite');
		$version->setKey('OLD');
		$version->setPasskeys('OLD-P');
		$this->versionMapper->method('findById')->willReturn($version);
		$this->versionMapper->method('update')->willReturnArgument(0);
		$this->db->expects($this->exactly(2))->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$written = $this->service->commitSecret(migration: $this->migration(), ownerId: 'alice', secretId: 's-1', key: 'NK', login: 'NL', additionalFields: 'NF', totp: 'NT');
		$this->assertSame(['NK', 'NL', 'NF', 'NT', null, 'new-suite'], [$written->getKey(), $written->getLogin(), $written->getAdditionalFields(), $written->getTotp(), $written->getPasskeys(), $written->getEncryptionSuiteId()]);

		$writtenVersion = $this->service->commitVersion(migration: $this->migration(), ownerId: 'alice', versionId: 'v-1', key: 'VK', login: null, additionalFields: 'VF', passkeys: 'VP');
		$this->assertSame(['VK', null, 'VF', 'VP', 'new-suite'], [$writtenVersion->getKey(), $writtenVersion->getLogin(), $writtenVersion->getAdditionalFields(), $writtenVersion->getPasskeys(), $writtenVersion->getEncryptionSuiteId()]);
		$this->assertNull($writtenVersion->getTotp());
	}//end testACommitWritesEveryResealedField()
}//end class
