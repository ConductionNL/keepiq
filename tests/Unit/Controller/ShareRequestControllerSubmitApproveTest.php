<?php

/**
 * Contract tests for submitting and approving share requests.
 *
 * A share request asks a secret's owner to share it onward. The SESSION user
 * is the requester on submission and the owner on approval, never a value
 * from the request body; anonymous callers are refused, a refused submission
 * is a 400 and a refused approval a 403.
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
use OCA\Keepiq\Controller\ShareRequestController;
use OCA\Keepiq\Service\ShareRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShareRequestControllerSubmitApproveTest extends TestCase {

	/**
	 * The mocked share request service.
	 *
	 * @var ShareRequestService&MockObject
	 */
	private ShareRequestService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var ShareRequestController
	 */
	private ShareRequestController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: ShareRequestService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new ShareRequestController(
			request: $this->createMock(originalClassName: IRequest::class),
			shareRequestService: $this->service,
			userSession: $this->session,
		);
	}//end setUp()

	/**
	 * Sign a user in on the mocked session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Anonymous submission and approval are refused before the service runs.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('submitShareRequest');
		$this->service->expects($this->never())->method('approveShareRequest');

		$submitted = $this->controller->create(sourceSecretId: 's-1', targetUserId: 'carol');
		$approved = $this->controller->approve(sourceSecretId: 's-1', requesterId: 'bob', targetUserId: 'carol');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $submitted->getStatus());
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $approved->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A submission names the session user as requester and answers 201.
	 *
	 * @return void
	 */
	public function testASubmissionNamesTheSessionUserAsRequester(): void {
		$this->signIn(uid: 'bob');
		$this->service->expects($this->once())->method('submitShareRequest')->with('s-1', 'carol', 'bob');

		$response = $this->controller->create(sourceSecretId: 's-1', targetUserId: 'carol');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: ['status' => 'submitted'], actual: $response->getData());
	}//end testASubmissionNamesTheSessionUserAsRequester()

	/**
	 * A refused submission is a 400 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusedSubmissionIs400(): void {
		$this->signIn(uid: 'bob');
		$this->service->method('submitShareRequest')->willThrowException(new InvalidArgumentException(message: 'Re-sharing is disabled'));

		$response = $this->controller->create(sourceSecretId: 's-1', targetUserId: 'carol');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Re-sharing is disabled'], actual: $response->getData());
	}//end testARefusedSubmissionIs400()

	/**
	 * An approval names the session user as owner and returns the service's answer.
	 *
	 * @return void
	 */
	public function testAnApprovalNamesTheSessionUserAsOwner(): void {
		$this->signIn(uid: 'alice');
		$answer = ['sourceSecretId' => 's-1', 'targetUserId' => 'carol', 'status' => 'approved'];
		$this->service->expects($this->once())->method('approveShareRequest')
			->with(['sourceSecretId' => 's-1', 'requesterId' => 'bob', 'targetUserId' => 'carol'], 'alice')
			->willReturn($answer);

		$this->assertSame(
			expected: $answer,
			actual: $this->controller->approve(sourceSecretId: 's-1', requesterId: 'bob', targetUserId: 'carol')->getData()
		);
	}//end testAnApprovalNamesTheSessionUserAsOwner()

	/**
	 * An approval by someone who does not own the secret is a 403.
	 *
	 * @return void
	 */
	public function testAnApprovalByANonOwnerIs403(): void {
		$this->signIn(uid: 'mallory');
		$this->service->method('approveShareRequest')->willThrowException(new InvalidArgumentException(message: 'Only the owner can approve'));

		$response = $this->controller->approve(sourceSecretId: 's-1', requesterId: 'bob', targetUserId: 'carol');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Only the owner can approve'], actual: $response->getData());
	}//end testAnApprovalByANonOwnerIs403()
}//end class
