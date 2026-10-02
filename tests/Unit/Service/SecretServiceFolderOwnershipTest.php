<?php

/**
 * SecretService folder ownership tests (keepiq#795).
 *
 * A secret may only be filed in a folder its owner owns. Before this, create,
 * update and the application paths copied `folderId` from the request as is,
 * so a user could plant a secret in another user's folder by its id: the
 * folder owner's delete then counted and purged a secret that was not theirs.
 *
 * The guard is the real FolderOwnershipGuard over a mocked FolderMapper.
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
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that SecretService refuses a folder its owner does not own.
 */
class SecretServiceFolderOwnershipTest extends TestCase {

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $mapper;

	/**
	 * @var EncryptionSuiteMapper&MockObject
	 */
	private EncryptionSuiteMapper&MockObject $suiteMapper;

	/**
	 * The service under test.
	 *
	 * @var SecretService
	 */
	private SecretService $service;

	/**
	 * Wire the service with the real folder guard over one folder alice owns.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$migrationService = $this->createMock(MigrationService::class);
		$migrationService->method('isWriteLocked')->willReturn(false);

		$aliceFolder = new Folder();
		$aliceFolder->setId('folder-alice');
		$aliceFolder->setName('Private');
		$aliceFolder->setOwnerType('user');
		$aliceFolder->setOwnerId('alice');
		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($aliceFolder): Folder {
				if ($id === 'folder-alice') {
					return $aliceFolder;
				}

				throw new DoesNotExistException('Folder not found');
			}
		);

		// Positional on purpose: the guard is the constructor's last argument.
		$this->service = new SecretService(
			$this->mapper,
			$typeService,
			$this->suiteMapper,
			$migrationService,
			$this->createMock(LinkShareService::class),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			new AuditEventFactory(),
			new FolderOwnershipGuard($folderMapper),
		);
	}//end setUp()

	/**
	 * A secret owned by bob, sitting at the vault root.
	 *
	 * @return Secret
	 */
	private function bobsSecret(): Secret {
		$secret = new Secret();
		$secret->setId('secret-bob');
		$secret->setName('Bob mail');
		$secret->setKey('CIPHERTEXT');
		$secret->setOwnerType('user');
		$secret->setOwnerId('bob');
		$secret->setEncryptionSuiteId('suite-1');
		return $secret;
	}//end bobsSecret()

	/**
	 * Bob cannot create a secret in alice's folder.
	 *
	 * @return void
	 */
	public function testCreateRefusesAFolderOfAnotherUser(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(ForbiddenException::class);
		$this->service->create(
			data: ['name' => 'Planted', 'key' => 'CIPHERTEXT', 'folderId' => 'folder-alice'],
			userId: 'bob',
		);
	}//end testCreateRefusesAFolderOfAnotherUser()

	/**
	 * A folder id that does not exist is refused as not found.
	 *
	 * @return void
	 */
	public function testCreateRefusesAMissingFolder(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(NotFoundException::class);
		$this->service->create(
			data: ['name' => 'Lost', 'key' => 'CIPHERTEXT', 'folderId' => 'folder-gone'],
			userId: 'bob',
		);
	}//end testCreateRefusesAMissingFolder()

	/**
	 * Alice can still file a secret in her own folder.
	 *
	 * @return void
	 */
	public function testCreateAcceptsTheOwnersFolder(): void {
		$this->mapper->expects($this->once())->method('insert');

		$secret = $this->service->create(
			data: ['name' => 'Mine', 'key' => 'CIPHERTEXT', 'folderId' => 'folder-alice'],
			userId: 'alice',
		);

		$this->assertSame('folder-alice', $secret->getFolderId());
	}//end testCreateAcceptsTheOwnersFolder()

	/**
	 * Bob cannot move his secret into alice's folder.
	 *
	 * @return void
	 */
	public function testUpdateRefusesMovingIntoAFolderOfAnotherUser(): void {
		$this->mapper->method('findById')->willReturn($this->bobsSecret());
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->service->update(id: 'secret-bob', data: ['folderId' => 'folder-alice'], userId: 'bob');
	}//end testUpdateRefusesMovingIntoAFolderOfAnotherUser()

	/**
	 * An application secret written through the web app cannot be filed in
	 * the writing user's own folder either (keepiq#873): the application owns
	 * the secret and no folder, and the user's folder delete would purge it.
	 *
	 * @return void
	 */
	public function testCreateForApplicationRefusesAFolderTheWritingUserOwns(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->createForApplication(
			data: ['name' => 'App key', 'key' => 'CIPHERTEXT', 'folderId' => 'folder-alice'],
			applicationId: 'app-1',
			writingUserId: 'alice',
		);
	}//end testCreateForApplicationRefusesAFolderTheWritingUserOwns()

	/**
	 * Nor in a folder of another user.
	 *
	 * @return void
	 */
	public function testCreateForApplicationRefusesAFolderOfAnotherUser(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->createForApplication(
			data: ['name' => 'App key', 'key' => 'CIPHERTEXT', 'folderId' => 'folder-alice'],
			applicationId: 'app-1',
			writingUserId: 'bob',
		);
	}//end testCreateForApplicationRefusesAFolderOfAnotherUser()

	/**
	 * Without a folder the web write still lands at the root.
	 *
	 * @return void
	 */
	public function testCreateForApplicationWithoutAFolderIsStored(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$secret = $this->service->createForApplication(
			data: ['name' => 'App key', 'key' => 'CIPHERTEXT'],
			applicationId: 'app-1',
			writingUserId: 'alice',
		);
		$this->assertNull($secret->getFolderId());
		$this->assertSame('application', $secret->getOwnerType());
	}//end testCreateForApplicationWithoutAFolderIsStored()

	/**
	 * An application writing with its machine token owns no folder, so it
	 * cannot file a secret in one.
	 *
	 * @return void
	 */
	public function testMachineCreateRefusesAnyFolder(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->createByApplication(
			data: ['name' => 'App key', 'key' => 'CIPHERTEXT', 'folderId' => 'folder-alice'],
			applicationId: 'app-1',
		);
	}//end testMachineCreateRefusesAnyFolder()

	/**
	 * A machine update cannot move an application secret into a folder.
	 *
	 * @return void
	 */
	public function testMachineUpdateRefusesAnyFolder(): void {
		$secret = $this->bobsSecret();
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-1');
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->service->updateByApplication(id: 'secret-bob', data: ['folderId' => 'folder-alice'], applicationId: 'app-1');
	}//end testMachineUpdateRefusesAnyFolder()
}//end class
