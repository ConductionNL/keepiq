<?php

/**
 * SecretService create row tests.
 *
 * Pins the row create(), createForApplication() and createByApplication()
 * insert, column by column, the order of their refusals, and the folder and
 * team folder checks the owner path runs (allowed and refused).
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\SuiteBlockedException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\OrgOwnershipGuard;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;


/**
 * Tests the rows the three create paths insert.
 */
class SecretServiceCreateRowTest extends TestCase {

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $mapper;

	/**
	 * @var FolderOwnershipGuard&MockObject
	 */
	private FolderOwnershipGuard&MockObject $folders;

	/**
	 * @var OrgOwnershipGuard&MockObject
	 */
	private OrgOwnershipGuard&MockObject $orgOwnership;

	/**
	 * The rows inserted, in order.
	 *
	 * @var list<Secret>
	 */
	private array $inserted = [];

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
		$this->mapper->method('insert')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->inserted[] = $secret;
				return $secret;
			}
		);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturnCallback(
			static function (string $ownerType, string $ownerId): EncryptionSuite {
				if ($ownerId === 'no-suite') {
					throw new DoesNotExistException('none');
				}

				$suite = new EncryptionSuite();
				$suite->setId('suite-'.$ownerType.'-'.$ownerId);
				$suite->setStatus('active');
				return $suite;
			}
		);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturnCallback(
			static fn ($typeId, string $namespace): string => 'type-'.($typeId ?? 'default').'-'.$namespace
		);
		$migrationService = $this->createMock(MigrationService::class);
		$migrationService->method('isWriteLocked')->willReturn(false);
		$this->folders = $this->createMock(FolderOwnershipGuard::class);
		$this->orgOwnership = $this->createMock(OrgOwnershipGuard::class);

		$auditService = $this->createMock(AuditService::class);
		$auditService->method('record')->willReturnCallback(
			function (AuditEvent $event) {
				$this->audited[] = $event;
				throw new \RuntimeException('not persisted in this test');
			}
		);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $typeService,
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
		);
	}//end setUp()

	/**
	 * Assert the row's generic columns: a fresh id, one time for every stamp, and the columns set.
	 *
	 * @param Secret       $secret  The inserted row
	 * @param list<string> $columns The columns the insert writes, sorted
	 *
	 * @return void
	 */
	private function assertFreshRow(Secret $secret, array $columns): void {
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $secret->getId());
		$this->assertNotNull($secret->getCreatedAt());
		$this->assertSame($secret->getCreatedAt(), $secret->getUpdatedAt());
		$this->assertSame($secret->getCreatedAt(), $secret->getKeyUpdatedAt());
		$fields = array_keys($secret->getUpdatedFields());
		sort($fields);
		$this->assertSame($columns, $fields);
	}//end assertFreshRow()

	/**
	 * An owner's new secret carries every field it was sent, the user's suite and the stamps.
	 *
	 * @return void
	 */
	public function testCreateStoresEveryField(): void {
		$this->folders->expects($this->once())->method('requireOwned')->with('folder-1', 'bob');
		$this->orgOwnership->expects($this->once())->method('assertAllowed')->with('bob', 'type-t1-bob', 'folder-1');

		$secret = $this->service->create(
			data: [
				'name' => '  Mail ',
				'key' => 'KEY',
				'url' => 'https://mail.test',
				'typeId' => 't1',
				'folderId' => 'folder-1',
				'login' => 'LOGIN',
				'additionalFields' => 'FIELDS',
				'totp' => 'TOTP',
				'passkeys' => 'PASSKEYS',
			],
			userId: 'bob'
		);

		$this->assertSame([$secret], $this->inserted);
		$this->assertSame('Mail', $secret->getName());
		$this->assertSame('KEY', $secret->getKey());
		$this->assertSame('https://mail.test', $secret->getUrl());
		$this->assertSame('type-t1-bob', $secret->getTypeId());
		$this->assertSame('folder-1', $secret->getFolderId());
		$this->assertSame('LOGIN', $secret->getLogin());
		$this->assertSame('FIELDS', $secret->getAdditionalFields());
		$this->assertSame('TOTP', $secret->getTotp());
		$this->assertSame('PASSKEYS', $secret->getPasskeys());
		$this->assertSame('suite-user-bob', $secret->getEncryptionSuiteId());
		$this->assertSame('user', $secret->getOwnerType());
		$this->assertSame('bob', $secret->getOwnerId());
		$this->assertEquals($secret->getCreatedAt(), $secret->getTotpUpdatedAt());
		$this->assertEquals($secret->getCreatedAt(), $secret->getPasskeysUpdatedAt());
		$this->assertFreshRow(
			secret: $secret,
			columns: ['additionalFields', 'createdAt', 'encryptionSuiteId', 'folderId', 'id', 'key', 'keyUpdatedAt', 'login', 'name', 'ownerId', 'ownerType', 'passkeys', 'passkeysUpdatedAt', 'totp', 'totpUpdatedAt', 'typeId', 'updatedAt', 'url']
		);
		$this->assertCount(1, $this->audited);
		$this->assertSame('secret.created', $this->audited[0]->getEventType());
		$this->assertSame('bob', $this->audited[0]->getActorId());
		$this->assertSame(['typeId' => 'type-t1-bob', 'folderId' => 'folder-1'], $this->audited[0]->getMetadata());
	}//end testCreateStoresEveryField()

	/**
	 * A minimal owner secret leaves the optional columns unset, and a placeholder may be keyless.
	 *
	 * @return void
	 */
	public function testCreateLeavesOptionalFieldsUnsetAndAllowsAnOptedInPlaceholder(): void {
		$secret = $this->service->create(data: ['name' => 'Mail', 'key' => 'KEY', 'url' => ''], userId: 'bob');
		$this->assertNull($secret->getUrl());
		$this->assertNull($secret->getFolderId());
		$this->assertSame('type-default-bob', $secret->getTypeId());
		$this->assertFreshRow(
			secret: $secret,
			columns: ['createdAt', 'encryptionSuiteId', 'id', 'key', 'keyUpdatedAt', 'name', 'ownerId', 'ownerType', 'typeId', 'updatedAt']
		);

		$placeholder = $this->service->create(data: ['name' => 'Asked'], userId: 'bob', allowUnfilled: true);
		$this->assertSame('', $placeholder->getKey());
	}//end testCreateLeavesOptionalFieldsUnsetAndAllowsAnOptedInPlaceholder()

	/**
	 * The owner path refuses, in order: a missing name or key, a folder that is
	 * not the user's, a user without a vault, a type the team folder policy refuses.
	 *
	 * @return void
	 */
	public function testCreateRefusalsInOrder(): void {
		$this->folders->method('requireOwned')->willReturnCallback(
			static function (string $id, string $userId): void {
				if ($id === 'theirs') {
					throw new ForbiddenException(message: 'Not your folder');
				}
			}
		);
		$this->orgOwnership->method('assertAllowed')->willThrowException(new ForbiddenException(message: 'Policy'));

		foreach ([
			[['name' => ' ', 'key' => 'KEY', 'folderId' => 'theirs'], 'bob', 'A secret requires a name and a key'],
			[['name' => 'Mail', 'folderId' => 'theirs'], 'bob', 'A secret requires a name and a key'],
			[['name' => 'Mail', 'key' => 'KEY', 'folderId' => 'theirs'], 'no-suite', 'Not your folder'],
			[['name' => 'Mail', 'key' => 'KEY'], 'bob', 'Policy'],
		] as [$data, $userId, $message]) {
			try {
				$this->service->create(data: $data, userId: $userId);
				$this->fail('Accepted '.json_encode($data));
			} catch (\Throwable $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->inserted);
	}//end testCreateRefusalsInOrder()

	/**
	 * A secret written for an application on a user's behalf is the application's,
	 * typed under the writing user, and unaudited here.
	 *
	 * @return void
	 */
	public function testCreateForApplicationStoresAnApplicationRow(): void {
		$secret = $this->service->createForApplication(
			data: ['name' => ' Token ', 'key' => 'KEY', 'url' => 'https://api.test', 'login' => 'L', 'additionalFields' => 'F', 'totp' => '', 'folderId' => ''],
			applicationId: 'app-1',
			writingUserId: 'alice'
		);

		$this->assertSame('Token', $secret->getName());
		$this->assertSame('https://api.test', $secret->getUrl());
		$this->assertSame('type-default-alice', $secret->getTypeId());
		$this->assertNull($secret->getFolderId());
		$this->assertSame('L', $secret->getLogin());
		$this->assertSame('F', $secret->getAdditionalFields());
		$this->assertNull($secret->getTotp());
		$this->assertSame('suite-application-app-1', $secret->getEncryptionSuiteId());
		$this->assertSame('application', $secret->getOwnerType());
		$this->assertSame('app-1', $secret->getOwnerId());
		$this->assertFreshRow(
			secret: $secret,
			columns: ['additionalFields', 'createdAt', 'encryptionSuiteId', 'id', 'key', 'keyUpdatedAt', 'login', 'name', 'ownerId', 'ownerType', 'typeId', 'updatedAt', 'url']
		);
		$this->assertSame([], $this->audited);
	}//end testCreateForApplicationStoresAnApplicationRow()

	/**
	 * A machine create is typed under the application, audited for it, and may
	 * be a keyless shell when it opts in.
	 *
	 * @return void
	 */
	public function testCreateByApplicationStoresAnAuditedApplicationRow(): void {
		$secret = $this->service->createByApplication(
			data: ['name' => 'Token', 'key' => 'KEY', 'typeId' => 't2'],
			applicationId: 'app-1'
		);

		$this->assertSame('type-t2-app-1', $secret->getTypeId());
		$this->assertSame('suite-application-app-1', $secret->getEncryptionSuiteId());
		$this->assertSame('application', $secret->getOwnerType());
		$this->assertFreshRow(
			secret: $secret,
			columns: ['createdAt', 'encryptionSuiteId', 'id', 'key', 'keyUpdatedAt', 'name', 'ownerId', 'ownerType', 'typeId', 'updatedAt']
		);
		$this->assertCount(1, $this->audited);
		$this->assertSame('app-1', $this->audited[0]->getActorId());
		$this->assertSame(['typeId' => 'type-t2-app-1', 'folderId' => null], $this->audited[0]->getMetadata());

		$shell = $this->service->createByApplication(data: ['name' => 'Asked'], applicationId: 'app-1', allowUnfilled: true);
		$this->assertSame('', $shell->getKey());
	}//end testCreateByApplicationStoresAnAuditedApplicationRow()

	/**
	 * Both application paths refuse, in order: a value blob, a missing
	 * application, a missing name or key, then a folder and a missing vault,
	 * which the two paths check the other way round.
	 *
	 * @return void
	 */
	public function testApplicationCreateRefusalsInOrder(): void {
		$folder = 'An application cannot file a secret in a folder';
		$noSuite = 'No active EncryptionSuite for application no-suite';
		$cases = [
			[['name' => '', 'totp' => 'T', 'folderId' => 'f'], '', 'totp and passkeys are not supported on an application-owned secret', 'totp and passkeys are not supported on an application-owned secret'],
			[['name' => '', 'folderId' => 'f'], '', 'applicationId is required', 'applicationId is required'],
			[['name' => '', 'key' => 'KEY', 'folderId' => 'f'], 'no-suite', 'A secret requires a name and a key', 'A secret requires a name and a key'],
			[['name' => 'Token', 'key' => 'KEY', 'folderId' => 'f'], 'no-suite', $folder, $noSuite],
			[['name' => 'Token', 'key' => 'KEY'], 'no-suite', $noSuite, $noSuite],
			[['name' => 'Token', 'key' => 'KEY', 'folderId' => 'f'], 'app-1', $folder, $folder],
		];
		foreach ($cases as [$data, $applicationId, $forMessage, $byMessage]) {
			try {
				$this->service->createForApplication(data: $data, applicationId: $applicationId, writingUserId: 'alice');
				$this->fail('createForApplication accepted '.json_encode($data));
			} catch (InvalidArgumentException|SuiteBlockedException $e) {
				$this->assertSame($forMessage, $e->getMessage());
			}

			try {
				$this->service->createByApplication(data: $data, applicationId: $applicationId);
				$this->fail('createByApplication accepted '.json_encode($data));
			} catch (InvalidArgumentException|SuiteBlockedException $e) {
				$this->assertSame($byMessage, $e->getMessage());
			}
		}

		$this->assertSame([], $this->inserted);
	}//end testApplicationCreateRefusalsInOrder()
}//end class
