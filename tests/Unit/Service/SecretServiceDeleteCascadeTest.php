<?php

/**
 * SecretService delete cascade tests.
 *
 * Pins what delete() and deleteByApplication() remove, in which order, the
 * audit event each records (deleted, purged by the owner, purged by
 * retention), and which rows each refuses or silently skips.
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCA\Keepiq\Service\SecretRequestService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the cascade and audit of the two delete paths.
 */
class SecretServiceDeleteCascadeTest extends TestCase {

	/**
	 * Every call the cascade made, in order, as "collaborator.method(id)".
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The audit events recorded, in order.
	 *
	 * @var list<AuditEvent>
	 */
	private array $audited = [];

	/**
	 * The row findById() answers with; null answers "does not exist".
	 *
	 * @var Secret|null
	 */
	private ?Secret $row = null;

	/**
	 * The service under test.
	 *
	 * @var SecretService
	 */
	private SecretService $service;

	/**
	 * A mock of the class that records each of the given methods as it is called.
	 *
	 * @param string       $class   The class to mock
	 * @param list<string> $methods The methods to record
	 *
	 * @return object
	 */
	private function recorder(string $class, array $methods): object {
		$mock = $this->createMock($class);
		$name = substr($class, strrpos($class, '\\') + 1);
		foreach ($methods as $method) {
			$answer = match ((string)(new \ReflectionMethod($class, $method))->getReturnType()) {
				'int' => 0,
				'array' => [],
				'bool' => false,
				default => null,
			};
			$mock->method($method)->willReturnCallback(
				function (...$args) use ($name, $method, $answer) {
					$this->calls[] = $name.'.'.$method.'('.implode(',', array_map(static fn ($a) => is_object($a) ? get_class($a) : (string)$a, $args)).')';
					return $answer;
				}
			);
		}

		return $mock;
	}//end recorder()

	/**
	 * Wire every optional cascade collaborator as a recorder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => $this->row ?? throw new DoesNotExistException('gone')
		);
		$mapper->method('delete')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->calls[] = 'SecretMapper.delete('.$secret->getId().')';
				return $secret;
			}
		);
		$migrationService = $this->createMock(MigrationService::class);
		$migrationService->method('isWriteLocked')->willReturn(false);
		$auditService = $this->createMock(AuditService::class);
		$auditService->method('record')->willReturnCallback(
			function (AuditEvent $event) {
				$this->audited[] = $event;
				throw new \RuntimeException('not persisted in this test');
			}
		);

		$this->service = new SecretService(
			mapper: $mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $migrationService,
			linkShareService: $this->recorder(class: LinkShareService::class, methods: ['deleteBySecretId']),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			secretRequestService: $this->recorder(class: SecretRequestService::class, methods: ['deleteAllForSecret']),
			shareService: $this->recorder(class: ShareService::class, methods: ['deleteAllForSecret']),
			groupShareMapper: $this->recorder(class: GroupShareMapper::class, methods: ['deleteBySecret']),
			secretDelegationMapper: $this->recorder(class: SecretDelegationMapper::class, methods: ['deleteBySecret']),
			auditService: $auditService,
			attachmentService: $this->recorder(class: AttachmentService::class, methods: ['deleteForSecret', 'deleteGrantsForSecretCopy']),
			versionService: $this->recorder(class: SecretVersionService::class, methods: ['deleteForSecret']),
			rotationService: $this->recorder(class: RotationPolicyService::class, methods: ['deleteForSecret']),
			auditEvents: new AuditEventFactory(),
			tagMapper: $this->recorder(class: SecretTagMapper::class, methods: ['deleteBySecret']),
		);
	}//end setUp()

	/**
	 * A row with the given owner.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function row(string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Mail');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$this->row = $secret;
		return $secret;
	}//end row()

	/**
	 * The sharing graph every delete removes, in order.
	 *
	 * @var list<string>
	 */
	private const SHARING_GRAPH = [
		'LinkShareService.deleteBySecretId(s-1)',
		'SecretRequestService.deleteAllForSecret(s-1)',
		'ShareService.deleteAllForSecret(s-1)',
		'GroupShareMapper.deleteBySecret(s-1)',
		'SecretDelegationMapper.deleteBySecret(s-1)',
	];

	/**
	 * An owner's delete removes the sharing graph, then the secret's own data, then the row.
	 *
	 * @return void
	 */
	public function testOwnerDeleteCascadesInOrderAndRecordsADeletion(): void {
		$this->row(ownerType: 'user', ownerId: 'bob');

		$this->service->delete(id: 's-1', userId: 'bob');

		$this->assertSame(
			array_merge(
				self::SHARING_GRAPH,
				[
					'AttachmentService.deleteForSecret(s-1)',
					'AttachmentService.deleteGrantsForSecretCopy(s-1)',
					'SecretVersionService.deleteForSecret(s-1)',
					'RotationPolicyService.deleteForSecret(s-1)',
					'SecretTagMapper.deleteBySecret(s-1)',
					'SecretMapper.delete(s-1)',
				]
			),
			$this->calls
		);
		$this->assertCount(1, $this->audited);
		$event = $this->audited[0];
		$this->assertSame('secret.deleted', $event->getEventType());
		$this->assertSame('bob', $event->getActorId());
		$this->assertSame('user', $event->getActorType());
		$this->assertSame('s-1', $event->getObjectId());
		$this->assertSame('Mail', $event->getObjectName());
		$this->assertSame([], $event->getMetadata());
	}//end testOwnerDeleteCascadesInOrderAndRecordsADeletion()

	/**
	 * A purge records who purged and why: the owner, or the system for retention.
	 *
	 * @return void
	 */
	public function testAPurgeRecordsItsReason(): void {
		$this->row(ownerType: 'user', ownerId: 'bob');

		$this->service->delete(id: 's-1', userId: 'bob', purgeReason: 'owner');
		$this->service->delete(id: 's-1', userId: 'bob', purgeReason: 'retention');

		$this->assertCount(2, $this->audited);
		[$owner, $retention] = $this->audited;
		$this->assertSame('secret.purged', $owner->getEventType());
		$this->assertSame('user', $owner->getActorType());
		$this->assertSame('bob', $owner->getActorId());
		$this->assertSame(['reason' => 'owner'], $owner->getMetadata());
		$this->assertSame('secret.purged', $retention->getEventType());
		$this->assertSame('system', $retention->getActorType());
		$this->assertSame(['reason' => 'retention'], $retention->getMetadata());
		$this->assertSame('Mail', $retention->getObjectName());
	}//end testAPurgeRecordsItsReason()

	/**
	 * An owner's delete refuses another user's row and an unknown one, and removes nothing.
	 *
	 * @return void
	 */
	public function testOwnerDeleteRefusesWhatIsNotTheUsers(): void {
		$this->row(ownerType: 'user', ownerId: 'alice');
		try {
			$this->service->delete(id: 's-1', userId: 'bob');
			$this->fail('Deleted another user\'s secret');
		} catch (ForbiddenException $e) {
			$this->assertSame('Secret belongs to another user', $e->getMessage());
		}

		$this->row(ownerType: 'application', ownerId: 'bob');
		try {
			$this->service->delete(id: 's-1', userId: 'bob');
			$this->fail('Deleted an application\'s secret');
		} catch (ForbiddenException $e) {
			$this->assertSame('Secret belongs to another user', $e->getMessage());
		}

		$this->row = null;
		try {
			$this->service->delete(id: 's-1', userId: 'bob');
			$this->fail('Deleted an unknown secret');
		} catch (NotFoundException $e) {
			$this->assertSame('Secret not found', $e->getMessage());
		}

		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->audited);
	}//end testOwnerDeleteRefusesWhatIsNotTheUsers()

	/**
	 * An application's delete removes the sharing graph and the row, and records the application.
	 *
	 * @return void
	 */
	public function testApplicationDeleteCascadesTheSharingGraph(): void {
		$this->row(ownerType: 'application', ownerId: 'app-1');

		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

		$this->assertSame(array_merge(self::SHARING_GRAPH, ['SecretMapper.delete(s-1)']), $this->calls);
		$this->assertCount(1, $this->audited);
		$event = $this->audited[0];
		$this->assertSame('secret.deleted', $event->getEventType());
		$this->assertSame('application', $event->getActorType());
		$this->assertSame('app-1', $event->getActorId());
		$this->assertSame('Mail', $event->getObjectName());
	}//end testApplicationDeleteCascadesTheSharingGraph()

	/**
	 * An application's delete is a silent no-op on an unknown row and on another vault's.
	 *
	 * @return void
	 */
	public function testApplicationDeleteSkipsWhatIsNotItsOwn(): void {
		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');
		$this->row(ownerType: 'application', ownerId: 'app-2');
		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');
		$this->row(ownerType: 'user', ownerId: 'app-1');
		$this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->audited);
	}//end testApplicationDeleteSkipsWhatIsNotItsOwn()
}//end class
