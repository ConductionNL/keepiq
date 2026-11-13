<?php

/**
 * Further unit tests for DeviceApprovalController: the feature status, the
 * pending list and the refusal of anonymous callers on every endpoint.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\DeviceApprovalController;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Service\DeviceApprovalService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the remaining branches of DeviceApprovalController.
 *
 * @covers \OCA\Keepiq\Controller\DeviceApprovalController
 * @uses   \OCA\Keepiq\Db\DeviceApproval
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class DeviceApprovalControllerMoreTest extends TestCase {

	/**
	 * The mocked approval service.
	 *
	 * @var DeviceApprovalService&MockObject
	 */
	private DeviceApprovalService&MockObject $approvals;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->approvals = $this->createMock(originalClassName: DeviceApprovalService::class);
	}//end setUp()

	/**
	 * Build the controller with the session resolving to the given user.
	 *
	 * @param string|null $userId The session user, or null for an anonymous caller
	 *
	 * @return DeviceApprovalController
	 */
	private function controller(?string $userId = 'alice'): DeviceApprovalController {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DeviceApprovalController(
			request: $this->createMock(originalClassName: IRequest::class),
			approvals: $this->approvals,
			userSession: $session,
		);
	}//end controller()

	/**
	 * The status endpoint reports whether the administrator switched the
	 * feature on.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function enabledProvider(): array {
		return [
			'on'  => [true],
			'off' => [false],
		];
	}//end enabledProvider()

	/**
	 * status() echoes the service's switch.
	 *
	 * @param bool $enabled The switch state
	 *
	 * @dataProvider enabledProvider
	 *
	 * @return void
	 */
	public function testStatusReportsTheSwitch(bool $enabled): void {
		$this->approvals->expects($this->once())->method('isEnabled')->willReturn($enabled);

		$response = $this->controller()->status();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['enabled' => $enabled], actual: $response->getData());
	}//end testStatusReportsTheSwitch()

	/**
	 * pending() lists the session user's own open requests, serialized.
	 *
	 * @return void
	 */
	public function testPendingListsTheSessionUsersRequests(): void {
		$row = new DeviceApproval();
		$row->setId('req-1');
		$row->setClientKind('web');
		$row->setDeviceLabel('Laptop');

		$this->approvals->expects($this->once())
			->method('pending')
			->with('alice')
			->willReturn([$row]);

		$response = $this->controller()->pending();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertCount(expectedCount: 1, haystack: $data);
		$this->assertSame(expected: 'req-1', actual: $data[0]['id']);
		$this->assertSame(expected: 'web', actual: $data[0]['clientKind']);
		$this->assertSame(expected: 'Laptop', actual: $data[0]['deviceLabel']);
	}//end testPendingListsTheSessionUsersRequests()

	/**
	 * Every endpoint as a call on the controller.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function endpointProvider(): array {
		return [
			'status'  => [fn (DeviceApprovalController $c) => $c->status()],
			'create'  => [fn (DeviceApprovalController $c) => $c->create('PUBKEY', 'web', 'Laptop')],
			'pending' => [fn (DeviceApprovalController $c) => $c->pending()],
			'deny'    => [fn (DeviceApprovalController $c) => $c->deny('req-1')],
			'approve' => [fn (DeviceApprovalController $c) => $c->approve('req-1', 'SEALED')],
			'show'    => [fn (DeviceApprovalController $c) => $c->show('req-1')],
		];
	}//end endpointProvider()

	/**
	 * Without a session user every endpoint answers 401 and the service is
	 * never consulted.
	 *
	 * @param callable $call The endpoint invocation
	 *
	 * @dataProvider endpointProvider
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesAnAnonymousCaller(callable $call): void {
		$this->approvals->expects($this->never())->method($this->anything());

		$response = $call($this->controller(userId: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testEveryEndpointRefusesAnAnonymousCaller()
}//end class
