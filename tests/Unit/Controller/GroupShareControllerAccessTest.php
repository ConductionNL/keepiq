<?php

/**
 * Unit tests for GroupShareController's list, refusals and revoke.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\GroupShareController;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\GroupShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GroupShareControllerTest drives the create fan-out and the scan-pending
 * answers. These cover the access side: the list is asked for the session
 * user, a forbidden create is a 403, a refused revoke is a 403 with the
 * reason and a successful one answers deleted, and anonymous callers are 401
 * on every endpoint without touching the service.
 *
 * @covers \OCA\Keepiq\Controller\GroupShareController
 * @uses   \OCA\Keepiq\Db\GroupShare
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Service\ShareRestriction
 * @uses   \OCA\Keepiq\Service\ShareRestrictionRules
 */
class GroupShareControllerAccessTest extends TestCase {

	/** @var GroupShareService&MockObject */
	private GroupShareService $service;

	/**
	 * Build the service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(originalClassName: GroupShareService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return GroupShareController
	 */
	private function controller(?string $uid = 'alice'): GroupShareController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new GroupShareController(
			request: $this->createMock(originalClassName: IRequest::class),
			groupShareService: $this->service,
			userSession: $session
		);
	}//end controller()

	/**
	 * The list is asked for the caller and serialised.
	 *
	 * @return void
	 */
	public function testIndexListsTheGroupSharesForTheCaller(): void {
		$share = new GroupShare();
		$share->setSecretId('s1');
		$share->setGroupId('finance');
		$this->service->expects($this->once())->method('getGroupSharesForSecret')->with('s1', 'alice')->willReturn([$share]);

		$response = $this->controller()->index(secretId: 's1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['finance'], array_column($response->getData(), 'groupId'));
	}//end testIndexListsTheGroupSharesForTheCaller()

	/**
	 * A create the caller may not make is a 403 with the reason.
	 *
	 * @return void
	 */
	public function testAForbiddenCreateIs403(): void {
		$this->service->method('createGroupShare')->willThrowException(new ForbiddenException('Sharing is disabled for this vault'));

		$response = $this->controller()->create(secretId: 's1', groupId: 'finance');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Sharing is disabled for this vault'], $response->getData());
	}//end testAForbiddenCreateIs403()

	/**
	 * Revoke is done as the caller and answers deleted.
	 *
	 * @return void
	 */
	public function testDestroyRevokesAsTheCaller(): void {
		$this->service->expects($this->once())->method('revokeGroupShare')->with('g1', 'alice');

		$response = $this->controller()->destroy(id: 'g1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'deleted'], $response->getData());
	}//end testDestroyRevokesAsTheCaller()

	/**
	 * A revoke the caller may not make is a 403 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedRevokeIs403(): void {
		$this->service->method('revokeGroupShare')->willThrowException(new InvalidArgumentException('Not the owner'));

		$response = $this->controller()->destroy(id: 'g1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not the owner'], $response->getData());
	}//end testARefusedRevokeIs403()

	/**
	 * Anonymous callers are 401 on list, create and revoke.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAre401(): void {
		$this->service->expects($this->never())->method($this->anything());
		$controller = $this->controller(uid: null);

		foreach ([$controller->index(secretId: 's1'), $controller->create(secretId: 's1', groupId: 'g'), $controller->destroy(id: 'g1')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['message' => 'Unauthorized'], $response->getData());
		}
	}//end testAnonymousCallersAre401()
}//end class
