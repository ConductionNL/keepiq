<?php

/**
 * Unit tests for SecretService machine write-back methods.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests createByApplication / updateByApplication — the machine write-back
 * path (application is the principal, own-vault scoped, ciphertext-only).
 */
class SecretServiceMachineWriteTest extends TestCase {

	private SecretService $service;

	private $mapper;

	private $typeService;

	private $suiteMapper;

	/**
	 * Wire the service with mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->typeService = $this->createMock(SecretTypeService::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->typeService->method('resolveTypeForSecret')->willReturn('api_key');
	}//end setUp()

	/**
	 * Build an application-owned secret.
	 *
	 * @param string $id The secret id
	 * @param string $ownerId The owning application id
	 *
	 * @return Secret
	 */
	private function appSecret(string $id, string $ownerId = 'app-1'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('token');
		$secret->setKey('OLD-CIPHER');
		$secret->setOwnerType('application');
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$secret->setKeyUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		return $secret;
	}//end appSecret()

	/**
	 * createByApplication stores an application-owned secret with the app's
	 * active suite and the app as owner.
	 *
	 * @return void
	 */
	public function testCreateByApplication(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$captured = null;
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(
				function (Secret $s) use (&$captured) {
					$captured = $s;
					return $s;
				}
			);

		$secret = $this->service->createByApplication(
			data: ['name' => 'new-token', 'key' => 'CIPHER'],
			applicationId: 'app-1'
		);

		$this->assertSame('application', $secret->getOwnerType());
		$this->assertSame('app-1', $secret->getOwnerId());
		$this->assertSame('suite-1', $secret->getEncryptionSuiteId());
		$this->assertSame('CIPHER', $captured->getKey());
	}//end testCreateByApplication()

	/**
	 * createByApplication requires a name and key.
	 *
	 * @return void
	 */
	public function testCreateByApplicationRequiresFields(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createByApplication(data: ['name' => ''], applicationId: 'app-1');
	}//end testCreateByApplicationRequiresFields()

	/**
	 * An ORDINARY write-back still cannot store a valueless secret.
	 *
	 * The `allowUnfilled` opt-in exists for request shells only; if it ever
	 * became the default, a machine write-back that silently lost its
	 * ciphertext would persist an empty credential instead of failing.
	 *
	 * @return void
	 */
	public function testCreateByApplicationStillRefusesAnEmptyKeyByDefault(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A secret requires a name and a key');
		$this->service->createByApplication(
			data: ['name' => 'has-a-name', 'key' => ''],
			applicationId: 'app-1'
		);
	}//end testCreateByApplicationStillRefusesAnEmptyKeyByDefault()

	/**
	 * `allowUnfilled` permits the keyless secret-request shell.
	 *
	 * Regression guard for a defect that reached a live instance: the machine
	 * secret-request route returned 400 "A secret requires a name and a key" on
	 * every happy-path call, because the shell it must create carries no value
	 * until a human fills it in. No unit test could see it — the callers all
	 * mock SecretService — so this asserts the real validation directly.
	 *
	 * @return void
	 */
	public function testCreateByApplicationAllowsAnUnfilledShell(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$captured = null;
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(
				function (Secret $s) use (&$captured) {
					$captured = $s;
					return $s;
				}
			);

		$secret = $this->service->createByApplication(
			data: ['name' => 'Unfilled request', 'key' => ''],
			applicationId: 'app-1',
			allowUnfilled: true
		);

		$this->assertSame('application', $secret->getOwnerType());
		$this->assertSame('app-1', $secret->getOwnerId());
		$this->assertSame('', $captured->getKey());
	}//end testCreateByApplicationAllowsAnUnfilledShell()

	/**
	 * A missing NAME is refused even for an unfilled shell.
	 *
	 * @return void
	 */
	public function testUnfilledShellStillRequiresAName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->createByApplication(
			data: ['name' => '', 'key' => ''],
			applicationId: 'app-1',
			allowUnfilled: true
		);
	}//end testUnfilledShellStillRequiresAName()

	/**
	 * updateByApplication on a cross-vault secret raises NotFoundException
	 * (no existence oracle).
	 *
	 * @return void
	 */
	public function testUpdateByApplicationCrossVaultNotFound(): void {
		$this->mapper->method('findById')->willReturn($this->appSecret('s1', 'app-2'));

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(
			id: 's1',
			data: ['key' => 'NEW'],
			applicationId: 'app-1'
		);
	}//end testUpdateByApplicationCrossVaultNotFound()

	/**
	 * updateByApplication on a nonexistent id raises NotFoundException.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationMissingNotFound(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('none'));

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(id: 'x', data: [], applicationId: 'app-1');
	}//end testUpdateByApplicationMissingNotFound()

	/**
	 * updateByApplication advances updatedAt and, when the key blob
	 * changes, keyUpdatedAt.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationAdvancesTimestamps(): void {
		$secret = $this->appSecret('s1', 'app-1');
		$oldKeyU = $secret->getKeyUpdatedAt();
		$oldUpd = $secret->getUpdatedAt();
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->once())->method('update');

		$result = $this->service->updateByApplication(
			id: 's1',
			data: ['key' => 'ROTATED-CIPHER'],
			applicationId: 'app-1'
		);

		$this->assertSame('ROTATED-CIPHER', $result->getKey());
		$this->assertGreaterThanOrEqual($oldUpd, $result->getUpdatedAt());
		$this->assertGreaterThanOrEqual($oldKeyU, $result->getKeyUpdatedAt());
	}//end testUpdateByApplicationAdvancesTimestamps()

	/**
	 * updateByApplication with an empty key is rejected.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationEmptyKeyRejected(): void {
		$this->mapper->method('findById')->willReturn($this->appSecret('s1', 'app-1'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->updateByApplication(
			id: 's1',
			data: ['key' => ''],
			applicationId: 'app-1'
		);
	}//end testUpdateByApplicationEmptyKeyRejected()

	/**
	 * Build a service that records audit events and version snapshots, for
	 * the updateByApplication() branch tests (#152: NPath 12960, written
	 * before any simplification so a refactor has something to fail).
	 *
	 * @param array<int,AuditEvent> $audits Receives every recorded audit event
	 * @param array<int,Secret> $snapshots Receives every snapshotted pre-update row
	 *
	 * @return SecretService
	 */
	private function recordingService(array &$audits, array &$snapshots): SecretService {
		$auditService = $this->createMock(AuditService::class);
		$auditService->method('record')->willReturnCallback(
			function (AuditEvent $event) use (&$audits) {
				$audits[] = $event;
				return new \OCA\Keepiq\Db\AuditEntry();
			}
		);
		$versionService = $this->createMock(SecretVersionService::class);
		$versionService->method('snapshot')->willReturnCallback(
			function (Secret $preUpdate) use (&$snapshots) {
				$snapshots[] = $preUpdate;
				return new SecretVersion();
			}
		);

		return new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			auditService: $auditService,
			versionService: $versionService,
		);
	}//end recordingService()

	/**
	 * A user-owned row whose owner id equals the application id is still
	 * another vault: the owner TYPE is checked, not only the id.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationRefusesAUserRowWithTheSameOwnerId(): void {
		$secret = $this->appSecret('s1', 'app-1');
		$secret->setOwnerType('user');
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(id: 's1', data: ['name' => 'x'], applicationId: 'app-1');
	}//end testUpdateByApplicationRefusesAUserRowWithTheSameOwnerId()

	/**
	 * An ambiguous id is reported as not found, like a missing one.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationAmbiguousIdNotFound(): void {
		$this->mapper->method('findById')
			->willThrowException(new MultipleObjectsReturnedException('two'));

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(id: 'x', data: [], applicationId: 'app-1');
	}//end testUpdateByApplicationAmbiguousIdNotFound()

	/**
	 * A blank name is refused; a padded one is trimmed.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationNameIsTrimmedAndMustNotBeBlank(): void {
		$secret = $this->appSecret('s1', 'app-1');
		$this->mapper->method('findById')->willReturn($secret);

		$result = $this->service->updateByApplication(id: 's1', data: ['name' => '  renamed  '], applicationId: 'app-1');
		$this->assertSame('renamed', $result->getName());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret name cannot be empty');
		$this->service->updateByApplication(id: 's1', data: ['name' => '   '], applicationId: 'app-1');
	}//end testUpdateByApplicationNameIsTrimmedAndMustNotBeBlank()

	/**
	 * Only the submitted metadata fields change; an empty string clears to
	 * null, and an absent field is left alone.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationWritesOnlyTheSubmittedMetadata(): void {
		$secret = $this->appSecret('s1', 'app-1');
		$secret->setUrl('https://old.example');
		$secret->setLogin('old-login');
		$secret->setAdditionalFields('OLD-FIELDS');
		$this->mapper->method('findById')->willReturn($secret);

		$result = $this->service->updateByApplication(
			id: 's1',
			data: ['url' => 'https://new.example', 'login' => '', 'folderId' => null],
			applicationId: 'app-1'
		);

		$this->assertSame('https://new.example', $result->getUrl());
		$this->assertNull($result->getLogin(), 'an empty login clears to null');
		$this->assertNull($result->getFolderId(), 'clearing the folder stays allowed');
		$this->assertSame('OLD-FIELDS', $result->getAdditionalFields(), 'an absent field is untouched');
		$this->assertSame('OLD-CIPHER', $result->getKey(), 'an absent key is untouched');

		$result = $this->service->updateByApplication(
			id: 's1',
			data: ['additionalFields' => 'NEW-FIELDS'],
			applicationId: 'app-1'
		);
		$this->assertSame('NEW-FIELDS', $result->getAdditionalFields());
	}//end testUpdateByApplicationWritesOnlyTheSubmittedMetadata()

	/**
	 * Re-sending the same key ciphertext is not a rotation: keyUpdatedAt and
	 * an open possibly-compromised warning both stand, and the audit names
	 * the submitted fields.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationUnchangedKeyIsNotARotation(): void {
		$audits = [];
		$snapshots = [];
		$service = $this->recordingService($audits, $snapshots);

		$secret = $this->appSecret('s1', 'app-1');
		$flaggedAt = new DateTime('2026-02-01T00:00:00+00:00');
		$secret->setPossiblyCompromisedAt($flaggedAt);
		$keyUpdatedAt = $secret->getKeyUpdatedAt();
		$this->mapper->method('findById')->willReturn($secret);

		$result = $service->updateByApplication(
			id: 's1',
			data: ['key' => 'OLD-CIPHER', 'login' => 'bot'],
			applicationId: 'app-1'
		);

		$this->assertSame($keyUpdatedAt, $result->getKeyUpdatedAt());
		$this->assertSame($flaggedAt, $result->getPossiblyCompromisedAt());
		$this->assertCount(1, $audits);
		$this->assertSame(['key', 'login'], $audits[0]->getMetadata()['changedFields']);
		$this->assertSame('application', $audits[0]->getActorType());
		$this->assertCount(1, $snapshots, 'the login change is a content change and is versioned');
	}//end testUpdateByApplicationUnchangedKeyIsNotARotation()

	/**
	 * A new key ciphertext rotates: keyUpdatedAt advances, the
	 * possibly-compromised warning clears, the audit reports only `key`, and
	 * the snapshot is the PRE-update row.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationChangedKeyRotatesAndClearsTheWarning(): void {
		$audits = [];
		$snapshots = [];
		$service = $this->recordingService($audits, $snapshots);

		$secret = $this->appSecret('s1', 'app-1');
		$secret->setPossiblyCompromisedAt(new DateTime('2026-02-01T00:00:00+00:00'));
		$this->mapper->method('findById')->willReturn($secret);

		$result = $service->updateByApplication(
			id: 's1',
			data: ['key' => 'NEW-CIPHER', 'name' => 'token'],
			applicationId: 'app-1'
		);

		$this->assertNull($result->getPossiblyCompromisedAt());
		$this->assertGreaterThan(new DateTime('2026-01-02T00:00:00+00:00'), $result->getKeyUpdatedAt());
		$this->assertSame(['key'], $audits[0]->getMetadata()['changedFields']);
		$this->assertCount(1, $snapshots);
		$this->assertSame('OLD-CIPHER', $snapshots[0]->getKey());
	}//end testUpdateByApplicationChangedKeyRotatesAndClearsTheWarning()

	/**
	 * A write that changes nothing is not versioned, but is still persisted
	 * (updatedAt) and audited.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationNoContentChangeIsNotVersioned(): void {
		$audits = [];
		$snapshots = [];
		$service = $this->recordingService($audits, $snapshots);

		$secret = $this->appSecret('s1', 'app-1');
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->once())->method('update');

		$service->updateByApplication(id: 's1', data: ['name' => 'token'], applicationId: 'app-1');

		$this->assertSame([], $snapshots);
		$this->assertCount(1, $audits);
		$this->assertSame(['name'], $audits[0]->getMetadata()['changedFields']);
	}//end testUpdateByApplicationNoContentChangeIsNotVersioned()

	/**
	 * A refused field leaves the row unwritten: validation happens before
	 * the update, so a half-applied write cannot reach the database.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationRefusedFieldWritesNothing(): void {
		$this->mapper->method('findById')->willReturn($this->appSecret('s1', 'app-1'));
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->service->updateByApplication(
			id: 's1',
			data: ['url' => 'https://x.example', 'folderId' => 'folder-1'],
			applicationId: 'app-1'
		);
	}//end testUpdateByApplicationRefusedFieldWritesNothing()
}//end class
