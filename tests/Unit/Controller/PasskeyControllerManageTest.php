<?php

/**
 * Unit tests for PasskeyController's list, login-options and revoke endpoints.
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
use OCA\Keepiq\Controller\PasskeyController;
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Service\PasskeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PasskeyControllerTest drives the ceremonies. These are the management
 * endpoints around them: the list and the login options are scoped to the
 * session user, a revoke maps "not yours / gone" to 404 and a refused revoke
 * (the last passkey of a passkey-only vault, say) to 403 with its reason, a
 * successful enrolment answers 201 with the public shape, and every endpoint
 * refuses an anonymous caller before the service is touched.
 *
 * @covers \OCA\Keepiq\Controller\PasskeyController
 * @uses   \OCA\Keepiq\Db\PasskeyCredential
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class PasskeyControllerManageTest extends TestCase {

	/** @var PasskeyService&MockObject */
	private PasskeyService $service;

	/**
	 * Build the service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(originalClassName: PasskeyService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return PasskeyController
	 */
	private function controller(?string $uid = 'alice'): PasskeyController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PasskeyController(
			request: $this->createMock(originalClassName: IRequest::class),
			service: $this->service,
			userSession: $session,
			throttler: $this->createMock(originalClassName: IThrottler::class),
		);
	}//end controller()

	/**
	 * A stored credential.
	 *
	 * @param string $id    The id.
	 * @param string $label The label.
	 *
	 * @return PasskeyCredential
	 */
	private static function credential(string $id, string $label): PasskeyCredential {
		$credential = new PasskeyCredential();
		$credential->setId($id);
		$credential->setLabel($label);

		return $credential;
	}//end credential()

	/**
	 * The list holds the caller's passkeys in their public shape.
	 *
	 * @return void
	 */
	public function testIndexListsTheCallersPasskeys(): void {
		$this->service->expects($this->once())->method('listForOwner')->with('alice')
			->willReturn([self::credential(id: 'p1', label: 'Laptop'), self::credential(id: 'p2', label: 'Phone')]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['p1', 'p2'], array_column($response->getData(), 'id'));
		$this->assertSame('Laptop', $response->getData()[0]['label']);
		$this->assertArrayNotHasKey('wrappedUnlockKey', $response->getData()[0]);
	}//end testIndexListsTheCallersPasskeys()

	/**
	 * Login options are asked for the caller and the named client.
	 *
	 * @return void
	 */
	public function testLoginOptionsAreScopedToTheCallerAndClient(): void {
		$options = ['challenge' => 'abc', 'allowCredentials' => []];
		$this->service->expects($this->once())->method('loginOptions')->with('alice', 'extension')->willReturn($options);

		$response = $this->controller()->loginOptions(client: 'extension');

		$this->assertSame($options, $response->getData());
	}//end testLoginOptionsAreScopedToTheCallerAndClient()

	/**
	 * A verified enrolment answers 201 with the public shape.
	 *
	 * @return void
	 */
	public function testAVerifiedEnrolmentIs201(): void {
		$this->service->method('enroll')->willReturn(self::credential(id: 'p9', label: 'Key'));

		$response = $this->controller()->create(credentialId: 'cred', wrappedUnlockKey: 'wrapped', label: 'Key');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('p9', $response->getData()['id']);
	}//end testAVerifiedEnrolmentIs201()

	/**
	 * Revoke is done as the caller and answers revoked.
	 *
	 * @return void
	 */
	public function testDestroyRevokesAsTheCaller(): void {
		$this->service->expects($this->once())->method('revoke')->with('alice', 'p1');

		$response = $this->controller()->destroy(id: 'p1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['revoked' => true], $response->getData());
	}//end testDestroyRevokesAsTheCaller()

	/**
	 * A passkey that is gone or not the caller's is a 404.
	 *
	 * @return void
	 */
	public function testDestroyOfAMissingPasskeyIs404(): void {
		$this->service->method('revoke')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->destroy(id: 'p1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Passkey not found'], $response->getData());
	}//end testDestroyOfAMissingPasskeyIs404()

	/**
	 * A refused revoke is a 403 carrying the reason.
	 *
	 * @return void
	 */
	public function testARefusedRevokeIs403WithItsReason(): void {
		$this->service->method('revoke')->willThrowException(new InvalidArgumentException('This is your only way to unlock'));

		$response = $this->controller()->destroy(id: 'p1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'This is your only way to unlock'], $response->getData());
	}//end testARefusedRevokeIs403WithItsReason()

	/**
	 * Anonymous callers are refused on every management endpoint.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAreRefused(): void {
		$this->service->expects($this->never())->method($this->anything());
		$controller = $this->controller(uid: null);

		foreach ([$controller->index(), $controller->loginOptions(), $controller->destroy(id: 'p1'), $controller->create()] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => 'Unauthenticated'], $response->getData());
		}
	}//end testAnonymousCallersAreRefused()
}//end class
