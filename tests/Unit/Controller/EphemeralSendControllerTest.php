<?php

/**
 * Unit tests for EphemeralSendController, the owner side of one-off sends.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Controller\EphemeralSendController;
use OCA\Keepiq\Db\EphemeralSend;
use OCA\Keepiq\Service\EphemeralSendService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The owner endpoints create, list and revoke a send only for the signed-in
 * user: every call is scoped to that user's uid, an anonymous caller gets a
 * 401 before the service is touched, a bad request is a 400 carrying the
 * service's message, and a send that is not the caller's is a plain 404.
 *
 * @covers \OCA\Keepiq\Controller\EphemeralSendController
 * @uses   \OCA\Keepiq\Db\EphemeralSend
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class EphemeralSendControllerTest extends TestCase {

	/** @var EphemeralSendService&MockObject */
	private EphemeralSendService $service;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(EphemeralSendService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return EphemeralSendController
	 */
	private function controller(?string $uid = 'alice'): EphemeralSendController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);

		return new EphemeralSendController(request: $this->request, service: $this->service, userSession: $this->session);
	}//end controller()

	/**
	 * A stored send.
	 *
	 * @param int $id The id.
	 *
	 * @return EphemeralSend
	 */
	private static function send(int $id): EphemeralSend {
		$send = new EphemeralSend();
		$send->setId($id);
		$send->setToken('tok-' . $id);
		$send->setPayloadType('text');
		$send->setHasPassword(true);
		$send->setMaxViews(3);
		$send->setViewCount(1);
		$send->setExpiresAt(new DateTime('2026-12-01T10:00:00+00:00'));

		return $send;
	}//end send()

	/**
	 * Create hands the request params to the service as the caller and answers 201.
	 *
	 * @return void
	 */
	public function testCreateIsScopedToTheCallerAndAnswers201(): void {
		$params = ['payload' => 'cipher', 'maxViews' => 3];
		$this->request->method('getParams')->willReturn($params);
		$this->service->expects($this->once())->method('create')->with('alice', $params)->willReturn(self::send(5));

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('tok-5', $response->getData()['token']);
		$this->assertSame(2, $response->getData()['remainingViews']);
		$this->assertArrayNotHasKey('encryptedPayload', $response->getData());
	}//end testCreateIsScopedToTheCallerAndAnswers201()

	/**
	 * A refused create is a 400 with the service's message.
	 *
	 * @return void
	 */
	public function testAnInvalidCreateIsA400WithTheReason(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('create')->willThrowException(new InvalidArgumentException('maxViews must be between 1 and 10'));

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'maxViews must be between 1 and 10'], $response->getData());
	}//end testAnInvalidCreateIsA400WithTheReason()

	/**
	 * Index lists only the caller's sends, serialised.
	 *
	 * @return void
	 */
	public function testIndexListsTheCallersSends(): void {
		$this->service->expects($this->once())->method('listForOwner')->with('alice')->willReturn([self::send(1), self::send(2)]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['tok-1', 'tok-2'], array_column($response->getData(), 'token'));
		$this->assertSame('2026-12-01T10:00:00+00:00', $response->getData()[0]['expiresAt']);
	}//end testIndexListsTheCallersSends()

	/**
	 * Destroy revokes as the caller.
	 *
	 * @return void
	 */
	public function testDestroyRevokesAsTheCaller(): void {
		$this->service->expects($this->once())->method('revoke')->with('9', 'alice');

		$response = $this->controller()->destroy(id: '9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['revoked' => true], $response->getData());
	}//end testDestroyRevokesAsTheCaller()

	/**
	 * A send that is missing or not the caller's is a 404.
	 *
	 * @return void
	 */
	public function testDestroyOfAnUnknownSendIs404(): void {
		$this->service->method('revoke')->willThrowException(new DoesNotExistException('nope'));

		$response = $this->controller()->destroy(id: '9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Send not found'], $response->getData());
	}//end testDestroyOfAnUnknownSendIs404()

	/**
	 * Without a user every endpoint is a 401 and the service is never called.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAreRefusedBeforeTheService(): void {
		$this->service->expects($this->never())->method($this->anything());
		$controller = $this->controller(uid: null);

		foreach ([$controller->create(), $controller->index(), $controller->destroy(id: '1')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['message' => 'Unauthorized'], $response->getData());
		}
	}//end testAnonymousCallersAreRefusedBeforeTheService()
}//end class
