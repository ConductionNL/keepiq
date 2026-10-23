<?php

/**
 * Unit tests for SecretUpdateController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\SecretUpdateController;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The update route forwards only the fields the request carries, maps a
 * stale offline edit to 409 with the current row, and runs an update
 * without a base once more when a save lands during it.
 */
class SecretUpdateControllerTest extends TestCase {
	private SecretService&MockObject $secretService;

	/**
	 * The controller for a request with these parameters.
	 *
	 * @param array<string,mixed> $params The request parameters
	 * @param SecretService|null $service A service to use instead of the mock
	 *
	 * @return SecretUpdateController
	 */
	private function controller(array $params, ?SecretService $service = null): SecretUpdateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => array_key_exists($name, $params) ? $params[$name] : $default
		);
		$this->secretService = $this->createMock(SecretService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SecretUpdateController(request: $request, secretService: $service ?? $this->secretService, userSession: $session);
	}//end controller()

	/**
	 * A secret with ciphertext.
	 *
	 * @return Secret
	 */
	private function secret(): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Router');
		$secret->setKey('ENCRYPTED');
		return $secret;
	}//end secret()

	/**
	 * Only present fields are forwarded; an explicit null clears; the merge
	 * count is an int.
	 *
	 * @return void
	 */
	public function testForwardsOnlyThePresentFields(): void {
		$controller = $this->controller(['name' => 'New', 'login' => null, 'mergedPending' => '2']);
		$this->secretService->expects($this->once())->method('update')
			->with('s-1', ['name' => 'New', 'login' => null, 'mergedPending' => 2], 'alice')
			->willReturn($this->secret());

		$this->assertSame(Http::STATUS_OK, $controller->update('s-1')->getStatus());
	}//end testForwardsOnlyThePresentFields()

	/**
	 * A stale base answers 409 with the current row at once; the update is
	 * not tried again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testAStaleBaseAnswers409WithTheCurrentRow(): void {
		$controller = $this->controller(['name' => 'Offline name', 'baseUpdatedAt' => '2026-10-01T09:00:00+00:00']);
		$this->secretService->expects($this->once())->method('update')
			->with('s-1', ['name' => 'Offline name', 'baseUpdatedAt' => '2026-10-01T09:00:00+00:00'], 'alice')
			->willThrowException(new StaleWriteException(current: $this->secret()));

		$response = $controller->update('s-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('ENCRYPTED', $response->getData()['current']['key']);
	}//end testAStaleBaseAnswers409WithTheCurrentRow()

	/**
	 * A save that lands between the read and the write of an update without
	 * a base does not fail it: the update runs once more on the row as it is
	 * now, and that save is kept as a version.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testAnUpdateWithoutABaseRunsOnceMoreOnTheCurrentRow(): void {
		$read = $this->secret();
		$read->setOwnerType('user');
		$read->setOwnerId('alice');
		$read->setUpdatedAt(new \DateTime('2026-10-02T10:00:00+00:00'));
		$moved = clone $read;
		$moved->setKey('SAVED-MEANWHILE');
		$moved->setUpdatedAt(new \DateTime('2026-10-02T10:00:01+00:00'));
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnOnConsecutiveCalls($read, $moved, $moved);
		$mapper->expects($this->exactly(2))->method('advanceVersion')->willReturnOnConsecutiveCalls(0, 1);
		$mapper->expects($this->once())->method('update')->with($this->identicalTo($moved))->willReturnArgument(0);
		$versions = $this->createPartialMock(SecretVersionService::class, ['snapshot']);
		$versions->expects($this->once())->method('snapshot')
			->with($this->callback(static fn (Secret $before): bool => $before->getKey() === 'SAVED-MEANWHILE'));
		$service = new SecretService(
			mapper: $mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			versionService: $versions,
			db: $this->createMock(IDBConnection::class),
		);

		$response = $this->controller(['key' => 'NEW-CIPHERTEXT'], $service)->update('s-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('NEW-CIPHERTEXT', $response->getData()['key']);
	}//end testAnUpdateWithoutABaseRunsOnceMoreOnTheCurrentRow()

	/**
	 * An update without a base whose row moves again during the second run
	 * answers 409 with the current row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testAnUpdateWithoutABaseThatMovesTwiceAnswers409(): void {
		$controller = $this->controller(['key' => 'NEW-CIPHERTEXT']);
		$this->secretService->expects($this->exactly(2))->method('update')
			->with('s-1', ['key' => 'NEW-CIPHERTEXT'], 'alice')
			->willThrowException(new StaleWriteException(current: $this->secret()));

		$response = $controller->update('s-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('ENCRYPTED', $response->getData()['current']['key']);
	}//end testAnUpdateWithoutABaseThatMovesTwiceAnswers409()

	/**
	 * A suite migration in progress answers 423.
	 *
	 * @return void
	 */
	public function testAWriteLockAnswers423(): void {
		$controller = $this->controller(['name' => 'X']);
		$this->secretService->method('update')->willThrowException(new WriteLockedException('locked'));

		$this->assertSame(423, $controller->update('s-1')->getStatus());
	}//end testAWriteLockAnswers423()
}//end class
