<?php

/**
 * Further unit tests for RotationController: batch flagging and marking a
 * flag rotated.
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
use OCA\Keepiq\Controller\RotationController;
use OCA\Keepiq\Service\RotationPolicyService;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `rotation#flagBatch` and `rotation#markRotated`.
 *
 * Both take their owner from the session; the flag id or secret ids come from
 * the route/body and are only ever passed on together with that owner.
 *
 * @covers \OCA\Keepiq\Controller\RotationController
 */
class RotationControllerMoreTest extends TestCase {

	/**
	 * The mocked rotation policy service.
	 *
	 * @var RotationPolicyService&MockObject
	 */
	private RotationPolicyService&MockObject $rotationService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rotationService = $this->createMock(originalClassName: RotationPolicyService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller with the session resolving to the given user.
	 *
	 * @param string|null $userId The session user, or null for an anonymous caller
	 *
	 * @return RotationController
	 */
	private function controller(?string $userId = 'alice'): RotationController {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$this->userSession->method('getUser')->willReturn($user);

		return new RotationController(
			request: $this->createMock(originalClassName: IRequest::class),
			rotationService: $this->rotationService,
			secretService: $this->createMock(originalClassName: SecretService::class),
			userSession: $this->userSession,
		);
	}//end controller()

	/**
	 * flagBatch forwards the ids as strings, scoped to the session user, and
	 * reports how many flags are open.
	 *
	 * @return void
	 */
	public function testFlagBatchForwardsStringIdsForTheSessionUser(): void {
		$this->rotationService->expects($this->once())
			->method('flagBatch')
			->with('alice', $this->identicalTo(value: ['42', 'secret-b']))
			->willReturn(2);

		$response = $this->controller()->flagBatch(secretIds: [42, 'secret-b']);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['flagged' => 2], actual: $response->getData());
	}//end testFlagBatchForwardsStringIdsForTheSessionUser()

	/**
	 * flagBatch refuses an anonymous caller and flags nothing.
	 *
	 * @return void
	 */
	public function testFlagBatchRefusesAnAnonymousCaller(): void {
		$this->rotationService->expects($this->never())->method('flagBatch');

		$response = $this->controller(userId: null)->flagBatch(secretIds: ['s-1']);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testFlagBatchRefusesAnAnonymousCaller()

	/**
	 * markRotated passes the routed flag and the session user, and returns
	 * the service's verdict verbatim.
	 *
	 * @return void
	 */
	public function testMarkRotatedReturnsTheServiceVerdict(): void {
		$verdict = ['resolved' => false, 'requiresRotation' => true];

		$this->rotationService->expects($this->once())
			->method('markRotated')
			->with('flag-1', 'alice')
			->willReturn($verdict);

		$response = $this->controller()->markRotated('flag-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $verdict, actual: $response->getData());
	}//end testMarkRotatedReturnsTheServiceVerdict()

	/**
	 * A flag the service does not find for this user is a 404 with its message.
	 *
	 * @return void
	 */
	public function testMarkRotatedReportsAnUnknownFlagAsNotFound(): void {
		$this->rotationService->expects($this->once())
			->method('markRotated')
			->with('flag-x', 'alice')
			->willThrowException(new InvalidArgumentException('Flag not found'));

		$response = $this->controller()->markRotated('flag-x');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Flag not found'], actual: $response->getData());
	}//end testMarkRotatedReportsAnUnknownFlagAsNotFound()

	/**
	 * markRotated refuses an anonymous caller and resolves nothing.
	 *
	 * @return void
	 */
	public function testMarkRotatedRefusesAnAnonymousCaller(): void {
		$this->rotationService->expects($this->never())->method('markRotated');

		$response = $this->controller(userId: null)->markRotated('flag-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testMarkRotatedRefusesAnAnonymousCaller()
}//end class
