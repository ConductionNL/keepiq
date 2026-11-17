<?php

/**
 * Withdraw and refusal-mapping tests for RecoveryUserController.
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

use OCA\Keepiq\Controller\RecoveryUserController;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\Contracts\RecoveryEnrolmentServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryRequestServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Withdrawing acts on the caller's own enrolment; a policy refusal is 403
 * with its message and a missing request is a plain 404.
 *
 * @covers \OCA\Keepiq\Controller\RecoveryUserController
 * @uses   \OCA\Keepiq\Exception\ForbiddenException
 * @uses   \OCA\Keepiq\Exception\NotFoundException
 */
class RecoveryUserControllerRefusalTest extends TestCase {

	/**
	 * The mocked enrolment service.
	 *
	 * @var RecoveryEnrolmentServiceInterface&MockObject
	 */
	private RecoveryEnrolmentServiceInterface&MockObject $enrolments;

	/**
	 * The mocked request service.
	 *
	 * @var RecoveryRequestServiceInterface&MockObject
	 */
	private RecoveryRequestServiceInterface&MockObject $requests;

	/**
	 * Fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->enrolments = $this->createMock(RecoveryEnrolmentServiceInterface::class);
		$this->requests = $this->createMock(RecoveryRequestServiceInterface::class);
	}//end setUp()

	/**
	 * The controller signed in as alice.
	 *
	 * @return RecoveryUserController
	 */
	private function controller(): RecoveryUserController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RecoveryUserController(
			request: $this->createMock(IRequest::class),
			enrolments: $this->enrolments,
			requests: $this->requests,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Withdraw removes the caller's enrolment and returns the status read after it.
	 *
	 * @return void
	 */
	public function testWithdrawRemovesTheCallersEnrolmentAndReturnsTheNewStatus(): void {
		$this->enrolments->expects($this->once())->method('withdraw')->with('alice');
		$this->enrolments->expects($this->once())
			->method('status')
			->with('alice')
			->willReturn(['enrolled' => false]);

		$response = $this->controller()->withdraw();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['enrolled' => false], $response->getData());
	}//end testWithdrawRemovesTheCallersEnrolmentAndReturnsTheNewStatus()

	/**
	 * A withdraw refused by the required policy is 403 with the policy's message, and no status is read.
	 *
	 * @return void
	 */
	public function testWithdrawRefusedByPolicyIs403(): void {
		$this->enrolments->method('withdraw')
			->willThrowException(new ForbiddenException('Enrolment is required by your organisation'));
		$this->enrolments->expects($this->never())->method('status');

		$response = $this->controller()->withdraw();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Enrolment is required by your organisation'], $response->getData());
	}//end testWithdrawRefusedByPolicyIs403()

	/**
	 * Completing a request the caller does not have is a 404 that does not echo the service's message.
	 *
	 * @return void
	 */
	public function testCompletingAnUnknownRequestIsAPlain404(): void {
		$this->requests->expects($this->once())
			->method('complete')
			->with('rq-9', 'alice')
			->willThrowException(new NotFoundException('request rq-9 of bob'));

		$response = $this->controller()->complete(id: 'rq-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testCompletingAnUnknownRequestIsAPlain404()
}//end class
