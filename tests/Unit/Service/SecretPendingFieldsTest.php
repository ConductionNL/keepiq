<?php

/**
 * Request-filled extra fields wait as pending blobs until the owner merges
 * them (keepiq#750): the owner's update drops exactly what it merged.
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

use OCA\Keepiq\Controller\SecretUpdateController;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The merge-drop, through the controller and the real service.
 */
class SecretPendingFieldsTest extends TestCase {

	/**
	 * Secret mapper mock.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $mapper;

	/**
	 * The stored secret.
	 *
	 * @var Secret
	 */
	private Secret $secret;

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The controller under test, over the real service.
	 *
	 * @var SecretUpdateController
	 */
	private SecretUpdateController $controller;

	/**
	 * Wire the controller over the real SecretService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secret = new Secret();
		$this->secret->setId('s-1');
		$this->secret->setName('API');
		$this->secret->setKey('CIPHER_KEY');
		$this->secret->setOwnerType('user');
		$this->secret->setOwnerId('alice');
		$this->secret->setAdditionalFields('OWN_BLOB');
		$this->secret->setPendingAdditionalFieldList(['FILL_1', 'FILL_2']);

		$this->mapper = $this->createMock(SecretMapper::class);
		$this->mapper->method('findById')->willReturn($this->secret);
		$this->mapper->method('update')->willReturnArgument(0);

		$migration = $this->createMock(MigrationService::class);
		$migration->method('isWriteLocked')->willReturn(false);

		// Positional on purpose, as in SecretServiceFolderOwnershipTest.
		$service = new SecretService(
			$this->mapper,
			$this->createMock(SecretTypeService::class),
			$this->createMock(EncryptionSuiteMapper::class),
			$migration,
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
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new SecretUpdateController(
			request: $this->request,
			secretService: $service,
			userSession: $session,
		);
	}//end setUp()

	/**
	 * The owner writes back the merged blob for the one fill it read; a fill
	 * that arrived after that read stays pending.
	 *
	 * @return void
	 */
	public function testTheOwnersMergedWriteDropsOnlyWhatItMerged(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => match ($name) {
				'additionalFields' => 'MERGED_BLOB',
				'mergedPending' => 1,
				default => $default,
			}
		);

		$response = $this->controller->update(id: 's-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('MERGED_BLOB', $this->secret->getAdditionalFields());
		$this->assertSame(['FILL_2'], $this->secret->pendingAdditionalFieldList());
	}//end testTheOwnersMergedWriteDropsOnlyWhatItMerged()

	/**
	 * An ordinary edit that merged nothing leaves the pending fills alone.
	 *
	 * @return void
	 */
	public function testAnEditWithoutAMergeKeepsThePendingFills(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => ($name === 'additionalFields' ? 'EDITED_BLOB' : $default)
		);

		$this->controller->update(id: 's-1');

		$this->assertSame(['FILL_1', 'FILL_2'], $this->secret->pendingAdditionalFieldList());
	}//end testAnEditWithoutAMergeKeepsThePendingFills()

	/**
	 * Merging everything clears the column.
	 *
	 * @return void
	 */
	public function testMergingEveryFillClearsTheColumn(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => match ($name) {
				'additionalFields' => 'MERGED_BLOB',
				'mergedPending' => 2,
				default => $default,
			}
		);

		$this->controller->update(id: 's-1');

		$this->assertNull($this->secret->getPendingAdditionalFields());
		$this->assertSame([], $this->secret->jsonSerialize()['pendingAdditionalFields']);
	}//end testMergingEveryFillClearsTheColumn()
}//end class
