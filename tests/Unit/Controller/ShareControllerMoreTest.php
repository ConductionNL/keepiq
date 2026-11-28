<?php

/**
 * Unit tests for ShareController's list and revoke endpoints and its
 * anonymous refusals.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\ShareController;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Listing and revoking are scoped to the session user: the uid reaches
 * ShareService unchanged, the service's rows are what the caller receives,
 * a revoke the caller may not make is a 403 carrying the reason, and an
 * anonymous caller is refused with a 401 before the service is touched.
 *
 * @covers \OCA\Keepiq\Controller\ShareController
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class ShareControllerMoreTest extends TestCase {

	/**
	 * The mocked share service.
	 *
	 * @var ShareService&MockObject
	 */
	private ShareService&MockObject $shareService;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->shareService = $this->createMock(originalClassName: ShareService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return ShareController
	 */
	private function controller(?string $uid = 'alice'): ShareController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ShareController(
			request: $this->createMock(originalClassName: IRequest::class),
			shareService: $this->shareService,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A share target whose serialization is known.
	 *
	 * @param array<string,mixed> $row The serialized row.
	 *
	 * @return ShareTarget&MockObject
	 */
	private function shareTarget(array $row): ShareTarget&MockObject {
		$entity = $this->createMock(originalClassName: ShareTarget::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}//end shareTarget()

	/**
	 * Each endpoint, called anonymously.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function anonymousCalls(): array {
		return [
			'index' => [static fn (ShareController $c) => $c->index(secretId: 'sec-1')],
			'update' => [static fn (ShareController $c) => $c->update(id: 'sh-1', useOnly: true)],
			'destroy' => [static fn (ShareController $c) => $c->destroy(id: 'sh-1')],
		];
	}//end anonymousCalls()

	/**
	 * An anonymous caller gets 401 and the share service is never asked.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @return void
	 *
	 * @dataProvider anonymousCalls
	 */
	public function testAnAnonymousCallerIsRefusedBeforeTheService(callable $call): void {
		$this->shareService->expects($this->never())->method($this->anything());

		$response = $call($this->controller(uid: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testAnAnonymousCallerIsRefusedBeforeTheService()

	/**
	 * The list is the session user's view of the secret's shares, each row
	 * serialised in the order the service gave.
	 *
	 * @return void
	 */
	public function testIndexListsTheSharesTheSessionUserMaySee(): void {
		$this->shareService->expects($this->once())
			->method('listSharesForSecret')
			->with('sec-1', 'alice')
			->willReturn(
				[
					$this->shareTarget(row: ['id' => 'sh-1', 'targetUserId' => 'bob']),
					$this->shareTarget(row: ['id' => 'sh-2', 'targetUserId' => 'carol']),
				]
			);

		$response = $this->controller()->index(secretId: 'sec-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: [['id' => 'sh-1', 'targetUserId' => 'bob'], ['id' => 'sh-2', 'targetUserId' => 'carol']],
			actual: $response->getData()
		);
	}//end testIndexListsTheSharesTheSessionUserMaySee()

	/**
	 * Revoking forwards the share id and the session uid and reports deleted.
	 *
	 * @return void
	 */
	public function testDestroyRevokesTheShareForTheSessionUser(): void {
		$this->shareService->expects($this->once())->method('revokeShare')->with('sh-1', 'alice');

		$response = $this->controller()->destroy(id: 'sh-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['status' => 'deleted'], actual: $response->getData());
	}//end testDestroyRevokesTheShareForTheSessionUser()

	/**
	 * A revoke by someone who neither owns nor delegates the source is a 403
	 * carrying the service's reason.
	 *
	 * @return void
	 */
	public function testDestroyByANonOwnerIsForbiddenWithTheReason(): void {
		$this->shareService->expects($this->once())
			->method('revokeShare')
			->with('sh-1', 'mallory')
			->willThrowException(new InvalidArgumentException(message: 'Not authorized to revoke this share'));

		$response = $this->controller(uid: 'mallory')->destroy(id: 'sh-1');

		$this->assertInstanceOf(expected: MessageResponse::class, actual: $response);
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Not authorized to revoke this share'], actual: $response->getData());
	}//end testDestroyByANonOwnerIsForbiddenWithTheReason()
}//end class
