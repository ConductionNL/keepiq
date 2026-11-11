<?php

/**
 * Golden outcomes of deleting a folder.
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

use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\FolderCascadeService;
use OCA\Keepiq\Service\FolderDeletionService;
use OCA\Keepiq\Service\FolderNameGuard;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\FolderService;
use OCA\Keepiq\Service\FolderTreeService;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may delete a folder, which deletions are refused with what, and the
 * reads, writes, log lines and audit event of each allowed one, in order.
 *
 * @covers \OCA\Keepiq\Service\FolderService
 * @covers \OCA\Keepiq\Service\FolderDeletionService
 */
final class FolderDeletionGoldenTest extends TestCase {
	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The scenarios: caller, direct secret count, subfolder ids, cascade,
	 * resolution, and the expected calls (the last one the outcome).
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function scenarios(): array {
		$audit = static fn (int $secrets, int $subfolders): array => ['audit', 'bob', 'folder.deleted_cascade', 'folder', 'f-1', 'Folder f-1', ['secretCount' => $secrets, 'subfolderCount' => $subfolders]];
		return [
			'not the owner'                 => ['mallory', 0, [], null, null, [['findById', 'f-1'], ['throws', 'OCA\Keepiq\Exception\ForbiddenException']]],
			'empty'                         => ['bob', 0, [], 'delete', null, [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['delete', 'f-1'],
				['info', 'Keepiq: empty folder f-1 deleted by bob'], ['returns'],
			]],
			'leaf without cascade'          => ['bob', 2, [], null, null, [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['throws', 'OCA\Keepiq\Exception\ConflictException'],
			]],
			'leaf with an unknown cascade'  => ['bob', 2, [], 'keep', null, [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['throws', 'OCA\Keepiq\Exception\ConflictException'],
			]],
			'leaf cascade delete'           => ['bob', 2, [], 'delete', null, [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['begin'],
				['deleteByFolderId', 'f-1'], ['delete', 'f-1'],
				['info', 'Keepiq: leaf folder f-1 deleted (cascade=delete)'], ['commit'], $audit(2, 0), ['returns'],
			]],
			'leaf cascade move'             => ['bob', 2, [], 'move', null, [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['begin'],
				['updateFolderForSecrets', 'f-1', 'root'], ['delete', 'f-1'],
				['info', 'Keepiq: leaf folder f-1 deleted (cascade=move)'], ['commit'], $audit(2, 0), ['returns'],
			]],
			'subfolders without resolution' => ['bob', 0, ['s-1'], 'delete', null, [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['throws', 'OCA\Keepiq\Exception\ConflictException'],
			]],
			'subfolders, a missing entry'   => ['bob', 0, ['s-1', 's-2'], null, ['subfolders' => ['s-1' => 'move']], [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['throws', 'InvalidArgumentException'],
			]],
			'subfolders kept, secrets moved' => ['bob', 1, ['s-1'], null, ['subfolders' => ['s-1' => 'keep']], [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['existsInParent', 'user', 'bob', 'root', 'Folder s-1', 's-1'],
				['begin'], ['updateFolderForSecrets', 'f-1', 'root'], ['update', 's-1', 'root'], ['delete', 'f-1'],
				['info', 'Keepiq: folder f-1 deleted with resolution by bob'], ['commit'], $audit(1, 1), ['returns'],
			]],
			'subfolders and secrets purged' => ['bob', 1, ['s-1'], 'move', ['directSecrets' => 'delete', 'subfolders' => ['s-1' => 'delete']], [
				['findById', 'f-1'], ['findChildren', 'f-1'], ['countByFolder', 'f-1'], ['begin'],
				['deleteByFolderId', 'f-1'], ['getSubtreeIds', 's-1'], ['deleteByFolderId', 's-1'], ['findById', 's-1'], ['delete', 's-1'],
				['delete', 'f-1'], ['info', 'Keepiq: folder f-1 deleted with resolution by bob'], ['commit'], $audit(1, 1), ['returns'],
			]],
		];
	}//end scenarios()

	/**
	 * A deletion refuses or proceeds exactly as pinned.
	 *
	 * @param string     $caller     The requesting user
	 * @param int        $secrets    The folder's direct secret count
	 * @param string[]   $subfolders The direct subfolder ids
	 * @param ?string    $cascade    The cascade shorthand
	 * @param array|null $resolution The resolution body
	 * @param array      $expected   The expected calls
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testDeletes(string $caller, int $secrets, array $subfolders, ?string $cascade, ?array $resolution, array $expected): void {
		$service = $this->service(secrets: $secrets, subfolders: $subfolders);

		try {
			$service->delete(id: 'f-1', cascade: $cascade, resolution: $resolution, userId: $caller);
			$this->calls[] = ['returns'];
		} catch (Throwable $e) {
			$this->calls[] = ['throws', $e::class];
		}

		self::assertSame($expected, $this->calls);
	}//end testDeletes()

	/**
	 * The service over recording mappers.
	 *
	 * @param int      $secrets    The folder's direct secret count
	 * @param string[] $subfolders The direct subfolder ids
	 *
	 * @return FolderService
	 */
	private function service(int $secrets, array $subfolders): FolderService {
		$folders = $this->folders(subfolders: $subfolders);
		$secretMapper = $this->secrets(count: $secrets);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['info', $message];
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->calls[] = ['begin'];
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->calls[] = ['commit'];
		});
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (AuditEvent $event): void {
			$this->calls[] = ['audit', $event->getActorId(), $event->getEventType(), $event->getObjectType(), $event->getObjectId(), $event->getObjectName(), $event->getMetadata()];
		});
		$nameGuard = new FolderNameGuard(mapper: $folders);
		$ownership = new FolderOwnershipGuard(mapper: $folders);

		return new FolderService(
			mapper: $folders,
			ownership: $ownership,
			tree: new FolderTreeService(mapper: $folders, ownership: $ownership, nameGuard: $nameGuard, logger: $logger),
			deletion: new FolderDeletionService(
				mapper: $folders,
				secretMapper: $secretMapper,
				cascade: new FolderCascadeService(
					mapper: $folders,
					secretMapper: $secretMapper,
					childData: new SecretChildDataCleaner(secretMapper: $secretMapper),
					nameGuard: $nameGuard,
				),
				db: $db,
				logger: $logger,
				eventDispatcher: $events,
			),
		);
	}//end service()

	/**
	 * A recording folder mapper over f-1 (owned by bob, under `root`) and its subfolders.
	 *
	 * @param string[] $subfolders The direct subfolder ids
	 *
	 * @return FolderMapper
	 */
	private function folders(array $subfolders): FolderMapper {
		$mapper = $this->createMock(FolderMapper::class);
		$mapper->method('findById')->willReturnCallback(function (string $id): Folder {
			$this->calls[] = ['findById', $id];
			return $this->folder(id: $id, parentId: $id === 'f-1' ? 'root' : 'f-1');
		});
		$mapper->method('findChildren')->willReturnCallback(function (string $id) use ($subfolders): array {
			$this->calls[] = ['findChildren', $id];
			return array_map(fn (string $sub): Folder => $this->folder(id: $sub, parentId: $id), $subfolders);
		});
		$mapper->method('delete')->willReturnCallback(function (Folder $folder): Folder {
			$this->calls[] = ['delete', $folder->getId()];
			return $folder;
		});
		$mapper->method('update')->willReturnCallback(function (Folder $folder): Folder {
			$this->calls[] = ['update', $folder->getId(), $folder->getParentId()];
			return $folder;
		});
		$mapper->method('existsInParent')->willReturnCallback(function (mixed ...$args): bool {
			$this->calls[] = array_merge(['existsInParent'], $args);
			return false;
		});
		$mapper->method('getSubtreeIds')->willReturnCallback(function (string $id): array {
			$this->calls[] = ['getSubtreeIds', $id];
			return [$id];
		});
		return $mapper;
	}//end folders()

	/**
	 * A recording secret mapper.
	 *
	 * @param int $count The folder's direct secret count
	 *
	 * @return SecretMapper
	 */
	private function secrets(int $count): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('countByFolder')->willReturnCallback(function (string $id) use ($count): int {
			$this->calls[] = ['countByFolder', $id];
			return $count;
		});
		$mapper->method('findByFolderId')->willReturnCallback(function (string $id): array {
			$this->calls[] = ['findByFolderId', $id];
			return [];
		});
		$mapper->method('deleteByFolderId')->willReturnCallback(function (string $id): int {
			$this->calls[] = ['deleteByFolderId', $id];
			return 0;
		});
		$mapper->method('updateFolderForSecrets')->willReturnCallback(function (string $id, ?string $target): int {
			$this->calls[] = ['updateFolderForSecrets', $id, $target];
			return 0;
		});
		return $mapper;
	}//end secrets()

	/**
	 * A folder owned by bob.
	 *
	 * @param string $id       The folder id
	 * @param string $parentId Its parent id
	 *
	 * @return Folder
	 */
	private function folder(string $id, string $parentId): Folder {
		$folder = new Folder();
		$folder->setId($id);
		$folder->setName('Folder ' . $id);
		$folder->setParentId($parentId);
		$folder->setOwnerType('user');
		$folder->setOwnerId('bob');
		return $folder;
	}//end folder()
}//end class
