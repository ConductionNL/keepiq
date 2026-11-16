<?php

/**
 * Unit tests for FederatedInboundController.
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

use OCA\Keepiq\Controller\FederatedInboundController;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\Contracts\FederatedInboundServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The inbound share routes act on the session user and map the receiving
 * side's refusals to HTTP statuses.
 *
 * @covers \OCA\Keepiq\Controller\FederatedInboundController
 */
class FederatedInboundControllerTest extends TestCase {

	private FederatedInboundServiceInterface&MockObject $inbound;

	private IUserSession&MockObject $userSession;

	private FederatedInboundController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->inbound     = $this->createMock(FederatedInboundServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller  = new FederatedInboundController(
			$this->createMock(IRequest::class),
			$this->inbound,
			$this->userSession,
		);
	}//end setUp()

	private function signIn(string $uid='bob'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	private function row(array $data): FederatedInbound&MockObject {
		$row = $this->createMock(FederatedInbound::class);
		$row->method('jsonSerialize')->willReturn($data);
		return $row;
	}//end row()

	public function testEveryRouteRefusesWithoutAUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->inbound->expects($this->never())->method($this->anything());

		foreach ([$this->controller->index(), $this->controller->accept('7'), $this->controller->decline('7')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['message' => 'Unauthorized'], $response->getData());
		}
	}//end testEveryRouteRefusesWithoutAUser()

	public function testIndexListsTheSessionUsersShares(): void {
		$this->signIn('bob');
		$this->inbound->expects($this->once())->method('listFor')->with('bob')
			->willReturn([$this->row(['id' => 2]), $this->row(['id' => 1])]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 2], ['id' => 1]], $response->getData());
	}//end testIndexListsTheSessionUsersShares()

	public function testAcceptReturnsTheAcceptedShare(): void {
		$this->signIn('bob');
		$this->inbound->expects($this->once())->method('accept')->with('7', 'bob')
			->willReturn($this->row(['id' => 7, 'status' => 'accepted']));

		$response = $this->controller->accept('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 7, 'status' => 'accepted'], $response->getData());
	}//end testAcceptReturnsTheAcceptedShare()

	public function testAcceptOfAnUnknownShareIsNotFound(): void {
		$this->signIn();
		$this->inbound->method('accept')->willThrowException(new NotFoundException('Share not found'));

		$response = $this->controller->accept('9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Share not found'], $response->getData());
	}//end testAcceptOfAnUnknownShareIsNotFound()

	public function testAcceptWithoutAnEncryptionSuiteIsAConflict(): void {
		$this->signIn();
		$this->inbound->method('accept')->willThrowException(new RuntimeException('no_suite'));

		$response = $this->controller->accept('7');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'no_suite'], $response->getData());
	}//end testAcceptWithoutAnEncryptionSuiteIsAConflict()

	public function testAcceptFailingAtTheSenderIsABadGateway(): void {
		$this->signIn();
		$this->inbound->method('accept')->willThrowException(new RuntimeException('fetch_failed'));

		$response = $this->controller->accept('7');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'fetch_failed'], $response->getData());
	}//end testAcceptFailingAtTheSenderIsABadGateway()

	public function testDeclineReturnsTheDeclinedShare(): void {
		$this->signIn('bob');
		$this->inbound->expects($this->once())->method('decline')->with('7', 'bob')
			->willReturn($this->row(['id' => 7, 'status' => 'declined']));

		$response = $this->controller->decline('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 7, 'status' => 'declined'], $response->getData());
	}//end testDeclineReturnsTheDeclinedShare()

	public function testDeclineOfAnUnknownShareIsNotFound(): void {
		$this->signIn();
		$this->inbound->method('decline')->willThrowException(new NotFoundException('Share not found'));

		$response = $this->controller->decline('9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Share not found'], $response->getData());
	}//end testDeclineOfAnUnknownShareIsNotFound()
}//end class
