<?php

/**
 * Unit tests for RecoveryUserController: enrolment, anonymous callers and the
 * mapping of a refused request onto HTTP statuses.
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
use OCA\Keepiq\Controller\RecoveryUserController;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the user-facing recovery endpoints.
 *
 * @covers \OCA\Keepiq\Controller\RecoveryUserController
 */
class RecoveryUserControllerTest extends TestCase {

	/**
	 * The mocked enrolment service.
	 *
	 * @var RecoveryEnrolmentService&MockObject
	 */
	private RecoveryEnrolmentService&MockObject $enrolments;

	/**
	 * The mocked request service.
	 *
	 * @var RecoveryRequestService&MockObject
	 */
	private RecoveryRequestService&MockObject $requests;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enrolments = $this->createMock(originalClassName: RecoveryEnrolmentService::class);
		$this->requests = $this->createMock(originalClassName: RecoveryRequestService::class);
	}//end setUp()

	/**
	 * Build the controller with the session resolving to the given user.
	 *
	 * @param string|null $userId The session user, or null for an anonymous caller
	 *
	 * @return RecoveryUserController
	 */
	private function controller(?string $userId = 'alice'): RecoveryUserController {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RecoveryUserController(
			request: $this->createMock(originalClassName: IRequest::class),
			enrolments: $this->enrolments,
			requests: $this->requests,
			userSession: $session,
		);
	}//end controller()

	/**
	 * enrol() stores the envelope for the session user and answers with the
	 * fresh enrolment status.
	 *
	 * @return void
	 */
	public function testEnrolStoresTheEnvelopeForTheSessionUserAndReturnsTheStatus(): void {
		$status = ['enrolled' => true, 'recoveryKeyId' => 'rk-1'];

		$this->enrolments->expects($this->once())
			->method('enrol')
			->with('alice', 'rk-1', 'SEALED-ENVELOPE');
		$this->enrolments->expects($this->once())
			->method('status')
			->with('alice')
			->willReturn($status);

		$response = $this->controller()->enrol(recoveryKeyId: 'rk-1', envelope: 'SEALED-ENVELOPE');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $status, actual: $response->getData());
	}//end testEnrolStoresTheEnvelopeForTheSessionUserAndReturnsTheStatus()

	/**
	 * A refused enrolment (e.g. a malformed envelope) is a 400 with the
	 * service's message, and no status is reported as if it had succeeded.
	 *
	 * @return void
	 */
	public function testARefusedEnrolmentIsABadRequest(): void {
		$this->enrolments->expects($this->once())
			->method('enrol')
			->with('alice', 'rk-1', 'garbage')
			->willThrowException(new InvalidArgumentException('Invalid envelope'));
		$this->enrolments->expects($this->never())->method('status');

		$response = $this->controller()->enrol(recoveryKeyId: 'rk-1', envelope: 'garbage');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid envelope'], actual: $response->getData());
	}//end testARefusedEnrolmentIsABadRequest()

	/**
	 * Every endpoint as a call on the controller.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function endpointProvider(): array {
		return [
			'enrolment'     => [fn (RecoveryUserController $c) => $c->enrolment()],
			'enrol'         => [fn (RecoveryUserController $c) => $c->enrol('rk-1', 'SEALED')],
			'withdraw'      => [fn (RecoveryUserController $c) => $c->withdraw()],
			'createRequest' => [fn (RecoveryUserController $c) => $c->createRequest('PUBKEY')],
			'myRequest'     => [fn (RecoveryUserController $c) => $c->myRequest()],
			'complete'      => [fn (RecoveryUserController $c) => $c->complete('req-1')],
		];
	}//end endpointProvider()

	/**
	 * Without a session user every endpoint answers 401 and neither service
	 * is consulted.
	 *
	 * @param callable $call The endpoint invocation
	 *
	 * @dataProvider endpointProvider
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesAnAnonymousCaller(callable $call): void {
		$this->enrolments->expects($this->never())->method($this->anything());
		$this->requests->expects($this->never())->method($this->anything());

		$response = $call($this->controller(userId: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testEveryEndpointRefusesAnAnonymousCaller()
}//end class
