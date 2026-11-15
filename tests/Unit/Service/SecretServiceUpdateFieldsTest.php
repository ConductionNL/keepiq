<?php

/**
 * SecretService partial-update field tests.
 *
 * Pins, field by field, what update() and updateByApplication() write, refuse
 * and audit, the folder and team-folder checks they run (allowed and refused)
 * and the partner-organisation notice a rename or new URL sends.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\OrgOwnershipGuard;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the field-by-field partial update of a secret.
 */
class SecretServiceUpdateFieldsTest extends TestCase {

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $mapper;

	/**
	 * @var SecretTypeService&MockObject
	 */
	private SecretTypeService&MockObject $typeService;

	/**
	 * @var FolderOwnershipGuard&MockObject
	 */
	private FolderOwnershipGuard&MockObject $folders;

	/**
	 * @var OrgOwnershipGuard&MockObject
	 */
	private OrgOwnershipGuard&MockObject $orgOwnership;

	/**
	 * @var FederatedShareService&MockObject
	 */
	private FederatedShareService&MockObject $federatedShares;

	/**
	 * The audit events recorded, in order.
	 *
	 * @var list<AuditEvent>
	 */
	private array $audited = [];

	/**
	 * The service under test.
	 *
	 * @var SecretService
	 */
	private SecretService $service;

	/**
	 * Wire the service with mocked collaborators that record what they are asked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$this->typeService = $this->createMock(SecretTypeService::class);
		$migrationService = $this->createMock(MigrationService::class);
		$migrationService->method('isWriteLocked')->willReturn(false);
		$this->folders = $this->createMock(FolderOwnershipGuard::class);
		$this->orgOwnership = $this->createMock(OrgOwnershipGuard::class);
		$this->federatedShares = $this->createMock(FederatedShareService::class);

		$auditService = $this->createMock(AuditService::class);
		$auditService->method('record')->willReturnCallback(
			function (AuditEvent $event) {
				$this->audited[] = $event;
				throw new \RuntimeException('not persisted in this test');
			}
		);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $suiteMapper,
			migrationService: $migrationService,
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			auditService: $auditService,
			auditEvents: new AuditEventFactory(),
			folderOwnership: $this->folders,
			orgOwnership: $this->orgOwnership,
			federatedShares: $this->federatedShares,
		);
	}//end setUp()

	/**
	 * A secret owned by the given owner, with every field set.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function secret(string $ownerType='user', string $ownerId='bob'): Secret {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setName('Mail');
		$secret->setUrl('https://mail.test');
		$secret->setLogin('LOGIN');
		$secret->setKey('KEY');
		$secret->setAdditionalFields('FIELDS');
		$secret->setFolderId('folder-1');
		$secret->setTypeId('type-1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setUpdatedAt(new DateTime('2026-01-01T00:00:00Z'));
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->method('advanceVersion')->willReturn(1);
		return $secret;
	}//end secret()

	/**
	 * The changed fields the last audit event named.
	 *
	 * @return list<string>
	 */
	private function auditedFields(): array {
		$this->assertCount(1, $this->audited);
		$this->assertSame('secret.updated', $this->audited[0]->getEventType());
		return $this->audited[0]->getMetadata()['changedFields'];
	}//end auditedFields()

	/**
	 * Every metadata and value field an owner sends is written and named in the audit.
	 *
	 * @return void
	 */
	public function testUpdateWritesEveryFieldAndAuditsThem(): void {
		$this->secret();
		$this->typeService->method('resolveTypeForSecret')->willReturnCallback(
			static fn ($typeId, string $userId): string => 'resolved-'.$typeId.'-'.$userId
		);
		$this->folders->expects($this->once())->method('requireOwned')->with('folder-2', 'bob');
		$this->orgOwnership->expects($this->once())->method('assertKept');
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 'secret-1',
			data: [
				'name' => '  Webmail  ',
				'url' => '',
				'folderId' => 'folder-2',
				'typeId' => 'type-2',
				'key' => 'KEY2',
				'login' => 'LOGIN2',
				'additionalFields' => '',
				'totp' => 'TOTP',
				'passkeys' => 'PASSKEYS',
				'unknown' => 'ignored',
			],
			userId: 'bob'
		);

		$this->assertSame('Webmail', $secret->getName());
		$this->assertNull($secret->getUrl());
		$this->assertSame('folder-2', $secret->getFolderId());
		$this->assertSame('resolved-type-2-bob', $secret->getTypeId());
		$this->assertSame('KEY2', $secret->getKey());
		$this->assertNotNull($secret->getKeyUpdatedAt());
		$this->assertSame('LOGIN2', $secret->getLogin());
		$this->assertNull($secret->getAdditionalFields());
		$this->assertSame('TOTP', $secret->getTotp());
		$this->assertSame('PASSKEYS', $secret->getPasskeys());
		$this->assertSame(
			['name', 'url', 'folderId', 'typeId', 'key', 'login', 'additionalFields', 'totp', 'passkeys'],
			$this->auditedFields()
		);
	}//end testUpdateWritesEveryFieldAndAuditsThem()

	/**
	 * Fields left out are kept, an unchanged folder is not checked again, and
	 * an unchanged key keeps its age.
	 *
	 * @return void
	 */
	public function testUpdateKeepsWhatIsNotSentAndChecksNoUnchangedFolder(): void {
		$this->secret();
		$this->folders->expects($this->never())->method('requireOwned');
		$this->typeService->expects($this->never())->method('resolveTypeForSecret');

		$secret = $this->service->update(
			id: 'secret-1',
			data: ['folderId' => 'folder-1', 'key' => 'KEY'],
			userId: 'bob'
		);

		$this->assertSame('Mail', $secret->getName());
		$this->assertSame('https://mail.test', $secret->getUrl());
		$this->assertSame('LOGIN', $secret->getLogin());
		$this->assertSame('FIELDS', $secret->getAdditionalFields());
		$this->assertSame('type-1', $secret->getTypeId());
		$this->assertNull($secret->getKeyUpdatedAt());
		$this->assertSame(['folderId', 'key'], $this->auditedFields());
	}//end testUpdateKeepsWhatIsNotSentAndChecksNoUnchangedFolder()

	/**
	 * Moving a secret to the vault root checks no folder.
	 *
	 * @return void
	 */
	public function testUpdateToTheRootChecksNoFolder(): void {
		$this->secret();
		$this->folders->expects($this->never())->method('requireOwned');

		$secret = $this->service->update(id: 'secret-1', data: ['folderId' => null], userId: 'bob');

		$this->assertNull($secret->getFolderId());
	}//end testUpdateToTheRootChecksNoFolder()

	/**
	 * A folder the owner does not own refuses the update before anything is written.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAFolderTheOwnerDoesNotOwn(): void {
		$this->secret();
		$this->folders->method('requireOwned')->willThrowException(new ForbiddenException(message: 'Not your folder'));
		$this->orgOwnership->expects($this->never())->method('assertKept');
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Not your folder');
		$this->service->update(id: 'secret-1', data: ['folderId' => 'folder-9'], userId: 'bob');
	}//end testUpdateRefusesAFolderTheOwnerDoesNotOwn()

	/**
	 * The team folder policy is asked with the row before and after the
	 * metadata fields, before the key is touched, and its refusal stops the write.
	 *
	 * @return void
	 */
	public function testUpdateRefusesWhatTheTeamFolderPolicyRefuses(): void {
		$this->secret();
		$this->orgOwnership->method('assertKept')->willReturnCallback(
			function (Secret $secret, Secret $before, string $userId): void {
				$this->assertSame('Renamed', $secret->getName());
				$this->assertSame('Mail', $before->getName());
				$this->assertSame('KEY', $secret->getKey());
				$this->assertSame('bob', $userId);
				throw new ForbiddenException(message: 'Policy');
			}
		);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Policy');
		$this->service->update(id: 'secret-1', data: ['name' => 'Renamed', 'key' => 'KEY2'], userId: 'bob');
	}//end testUpdateRefusesWhatTheTeamFolderPolicyRefuses()

	/**
	 * An empty name and an empty key are refused, the name first.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAnEmptyNameOrKey(): void {
		$this->secret();
		$this->mapper->expects($this->never())->method('update');

		try {
			$this->service->update(id: 'secret-1', data: ['name' => '  ', 'key' => ''], userId: 'bob');
			$this->fail('An empty name was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Secret name cannot be empty', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret key cannot be empty');
		$this->service->update(id: 'secret-1', data: ['key' => ''], userId: 'bob');
	}//end testUpdateRefusesAnEmptyNameOrKey()

	/**
	 * Partner recipients hear of a new name or URL, never of an unchanged one
	 * or of a save that also sends a value.
	 *
	 * @return void
	 */
	public function testUpdateTellsPartnersOfANewNameOrUrlOnly(): void {
		$this->secret();
		$this->federatedShares->expects($this->exactly(2))->method('detailsChanged')->with('secret-1', 'bob');

		$this->service->update(id: 'secret-1', data: ['name' => 'Renamed'], userId: 'bob');
		$this->service->update(id: 'secret-1', data: ['url' => 'https://new.test'], userId: 'bob');
		$this->service->update(id: 'secret-1', data: ['url' => 'https://new.test', 'name' => 'Renamed'], userId: 'bob');
		$this->service->update(id: 'secret-1', data: ['name' => 'Again', 'login' => 'NEWLOGIN'], userId: 'bob');
	}//end testUpdateTellsPartnersOfANewNameOrUrlOnly()

	/**
	 * Request-filled blobs merged into the additional fields are dropped with them.
	 *
	 * @return void
	 */
	public function testUpdateDropsTheMergedPendingBlobsWithTheAdditionalFields(): void {
		$secret = $this->secret();
		$secret->setPendingAdditionalFields('["A","B","C"]');

		$this->service->update(id: 'secret-1', data: ['additionalFields' => 'MERGED', 'mergedPending' => '2'], userId: 'bob');

		$this->assertSame('MERGED', $secret->getAdditionalFields());
		$this->assertSame('["C"]', $secret->getPendingAdditionalFields());
	}//end testUpdateDropsTheMergedPendingBlobsWithTheAdditionalFields()

	/**
	 * A machine write sets the plain fields and the key, names a changed key alone.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationWritesFieldsAndNamesAChangedKeyAlone(): void {
		$this->secret(ownerType: 'application', ownerId: 'app-1');
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->updateByApplication(
			id: 'secret-1',
			data: ['name' => ' App ', 'url' => '', 'folderId' => null, 'login' => 'L2', 'additionalFields' => 'F2', 'key' => 'KEY2'],
			applicationId: 'app-1'
		);

		$this->assertSame('App', $secret->getName());
		$this->assertNull($secret->getUrl());
		$this->assertNull($secret->getFolderId());
		$this->assertSame('L2', $secret->getLogin());
		$this->assertSame('F2', $secret->getAdditionalFields());
		$this->assertSame('KEY2', $secret->getKey());
		$this->assertNotNull($secret->getKeyUpdatedAt());
		$this->assertSame(['key'], $this->auditedFields());
	}//end testUpdateByApplicationWritesFieldsAndNamesAChangedKeyAlone()

	/**
	 * A machine write without a key change names what it sent.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationNamesTheSentFieldsWithoutAKeyChange(): void {
		$this->secret(ownerType: 'application', ownerId: 'app-1');

		$secret = $this->service->updateByApplication(
			id: 'secret-1',
			data: ['name' => 'App', 'key' => 'KEY'],
			applicationId: 'app-1'
		);

		$this->assertNull($secret->getKeyUpdatedAt());
		$this->assertSame(['name', 'key'], $this->auditedFields());
	}//end testUpdateByApplicationNamesTheSentFieldsWithoutAKeyChange()

	/**
	 * A machine write refuses an empty name, an empty key and another vault's row.
	 *
	 * @return void
	 */
	public function testUpdateByApplicationRefusals(): void {
		$this->secret(ownerType: 'application', ownerId: 'app-1');
		$this->mapper->expects($this->never())->method('update');

		foreach ([
			[['name' => ''], 'app-1', InvalidArgumentException::class, 'Secret name cannot be empty'],
			[['key' => ''], 'app-1', InvalidArgumentException::class, 'Secret key cannot be empty'],
			[['name' => 'X'], 'app-2', \OCA\Keepiq\Exception\NotFoundException::class, 'Secret not found'],
		] as [$data, $applicationId, $class, $message]) {
			try {
				$this->service->updateByApplication(id: 'secret-1', data: $data, applicationId: $applicationId);
				$this->fail('Accepted '.json_encode($data));
			} catch (\Throwable $e) {
				$this->assertInstanceOf($class, $e);
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testUpdateByApplicationRefusals()
}//end class
