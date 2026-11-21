<?php

/**
 * Unit tests for RecoveryKitController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\RecoveryKitController;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\Contracts\RecoveryKitServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The recovery kit routes act on the session user only and map the
 * service's refusals to HTTP statuses.
 *
 * @covers \OCA\Keepiq\Controller\RecoveryKitController
 */
class RecoveryKitControllerTest extends TestCase {

	private RecoveryKitServiceInterface&MockObject $kits;

	private IUserSession&MockObject $userSession;

	private RecoveryKitController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->kits        = $this->createMock(RecoveryKitServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller  = new RecoveryKitController(
			$this->createMock(IRequest::class),
			$this->kits,
			$this->userSession,
		);
	}//end setUp()

	private function signIn(string $uid='alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	public function testShowReturnsTheSessionUsersStatus(): void {
		$this->signIn();
		$this->kits->expects($this->once())->method('status')->with('alice')
			->willReturn(['exists' => true, 'createdAt' => '2026-01-01T00:00:00+00:00']);

		$response = $this->controller->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['exists' => true, 'createdAt' => '2026-01-01T00:00:00+00:00'], $response->getData());
	}//end testShowReturnsTheSessionUsersStatus()

	public function testEveryRouteRefusesWithoutAUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->kits->expects($this->never())->method($this->anything());

		foreach (['show', 'fetch', 'spend', 'revoke'] as $route) {
			$response = $this->controller->$route();
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $route);
			$this->assertSame(['message' => 'Unauthorized'], $response->getData(), $route);
		}

		$response = $this->controller->create('wrapped', '{"iterations":1}');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testEveryRouteRefusesWithoutAUser()

	public function testCreatePassesTheBrowserValuesAndAnswersCreated(): void {
		$this->signIn('bob');
		$this->kits->expects($this->once())->method('create')
			->with('bob', 'wrapped-key', '{"alg":"argon2id"}')
			->willReturn(['exists' => true]);

		$response = $this->controller->create('wrapped-key', '{"alg":"argon2id"}');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['exists' => true], $response->getData());
	}//end testCreatePassesTheBrowserValuesAndAnswersCreated()

	public function testCreateMapsAnInvalidArgumentToBadRequestWithItsMessage(): void {
		$this->signIn();
		$this->kits->method('create')->willThrowException(new InvalidArgumentException('kdf is not valid'));

		$response = $this->controller->create('', '');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'kdf is not valid'], $response->getData());
	}//end testCreateMapsAnInvalidArgumentToBadRequestWithItsMessage()

	public function testFetchReturnsTheWrappedCopy(): void {
		$this->signIn();
		$this->kits->expects($this->once())->method('fetch')->with('alice')
			->willReturn(['wrappedKey' => 'w', 'kdf' => 'k']);

		$response = $this->controller->fetch();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['wrappedKey' => 'w', 'kdf' => 'k'], $response->getData());
	}//end testFetchReturnsTheWrappedCopy()

	public function testFetchWithoutAKitIsNotFound(): void {
		$this->signIn();
		$this->kits->method('fetch')->willThrowException(new NotFoundException('no kit'));

		$response = $this->controller->fetch();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testFetchWithoutAKitIsNotFound()

	public function testSpendDeletesTheCopyThenReturnsTheNewStatus(): void {
		$this->signIn();
		$calls = [];
		$this->kits->expects($this->once())->method('spend')->with('alice')
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'spend';
			});
		$this->kits->expects($this->once())->method('status')->with('alice')
			->willReturnCallback(function () use (&$calls): array {
				$calls[] = 'status';
				return ['exists' => false];
			});

		$response = $this->controller->spend();

		$this->assertSame(['spend', 'status'], $calls);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['exists' => false], $response->getData());
	}//end testSpendDeletesTheCopyThenReturnsTheNewStatus()

	public function testRevokeDeletesTheKitThenReturnsTheNewStatus(): void {
		$this->signIn('carol');
		$calls = [];
		$this->kits->expects($this->once())->method('revoke')->with('carol')
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'revoke';
			});
		$this->kits->expects($this->once())->method('status')->with('carol')
			->willReturnCallback(function () use (&$calls): array {
				$calls[] = 'status';
				return ['exists' => false];
			});

		$response = $this->controller->revoke();

		$this->assertSame(['revoke', 'status'], $calls);
		$this->assertSame(['exists' => false], $response->getData());
	}//end testRevokeDeletesTheKitThenReturnsTheNewStatus()

	public function testRevokeWithoutAKitIsNotFound(): void {
		$this->signIn();
		$this->kits->method('revoke')->willThrowException(new NotFoundException('no kit'));
		$this->kits->expects($this->never())->method('status');

		$response = $this->controller->revoke();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testRevokeWithoutAKitIsNotFound()
}//end class
