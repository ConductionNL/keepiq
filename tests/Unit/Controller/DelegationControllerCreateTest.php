<?php

/**
 * Unit tests for DelegationController's list, create, handover and capability endpoints.
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
use OCA\Keepiq\Controller\DelegationController;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\DelegationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DelegationControllerTest covers reclaim. These cover the rest: the list is
 * the owner's alone (a non-owner is 403), a create is made by the session
 * user and maps a cross-organisation copy to 403 and a bad target to 400,
 * a handover is always to the caller, and capabilities answer for the caller.
 *
 * @covers \OCA\Keepiq\Controller\DelegationController
 * @uses   \OCA\Keepiq\Db\SecretDelegation
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class DelegationControllerCreateTest extends TestCase {

	/** @var DelegationService&MockObject */
	private DelegationService $service;

	/**
	 * Build the service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(originalClassName: DelegationService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return DelegationController
	 */
	private function controller(?string $uid = 'alice'): DelegationController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DelegationController(
			request: $this->createMock(originalClassName: IRequest::class),
			delegationService: $this->service,
			userSession: $session
		);
	}//end controller()

	/**
	 * A delegation row.
	 *
	 * @param string $id          The id.
	 * @param string $delegatedTo The delegate.
	 *
	 * @return SecretDelegation
	 */
	private static function delegation(string $id, string $delegatedTo): SecretDelegation {
		$row = new SecretDelegation();
		$row->setId($id);
		$row->setSecretId('s1');
		$row->setDelegatedTo($delegatedTo);
		$row->setInitiatedBy('alice');

		return $row;
	}//end delegation()

	/**
	 * The owner sees the delegations of a secret.
	 *
	 * @return void
	 */
	public function testTheOwnerListsTheDelegations(): void {
		$this->service->expects($this->once())->method('getDelegationsForSecret')->with('s1', 'alice')
			->willReturn([self::delegation(id: 'd1', delegatedTo: 'bob'), self::delegation(id: 'd2', delegatedTo: 'carol')]);

		$response = $this->controller()->index(secretId: 's1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['bob', 'carol'], array_column($response->getData(), 'delegatedTo'));
	}//end testTheOwnerListsTheDelegations()

	/**
	 * A non-owner asking for the list is a 403 with the reason.
	 *
	 * @return void
	 */
	public function testANonOwnerListingIs403(): void {
		$this->service->method('getDelegationsForSecret')->willThrowException(new InvalidArgumentException('Not the owner'));

		$response = $this->controller()->index(secretId: 's1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not the owner'], $response->getData());
	}//end testANonOwnerListingIs403()

	/**
	 * A create is initiated by the session user and answers 201.
	 *
	 * @return void
	 */
	public function testCreateIsInitiatedByTheCaller(): void {
		$this->service->expects($this->once())->method('createDelegation')->with('s1', 'bob', 'alice')
			->willReturn(self::delegation(id: 'd1', delegatedTo: 'bob'));

		$response = $this->controller()->create(secretId: 's1', delegatedTo: 'bob');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('d1', $response->getData()['id']);
		$this->assertSame('alice', $response->getData()['initiatedBy']);
	}//end testCreateIsInitiatedByTheCaller()

	/**
	 * A copy from another organisation cannot be delegated: 403.
	 *
	 * @return void
	 */
	public function testAForbiddenCreateIs403(): void {
		$this->service->method('createDelegation')->willThrowException(new ForbiddenException('A copy cannot be delegated'));

		$response = $this->controller()->create(secretId: 's1', delegatedTo: 'bob');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'A copy cannot be delegated'], $response->getData());
	}//end testAForbiddenCreateIs403()

	/**
	 * An invalid target is a 400.
	 *
	 * @return void
	 */
	public function testAnInvalidCreateIs400(): void {
		$this->service->method('createDelegation')->willThrowException(new InvalidArgumentException('Unknown user'));

		$response = $this->controller()->create(secretId: 's1', delegatedTo: 'nobody');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Unknown user'], $response->getData());
	}//end testAnInvalidCreateIs400()

	/**
	 * A handover always delegates to the caller, initiated by the caller.
	 *
	 * @return void
	 */
	public function testAHandoverIsToTheCaller(): void {
		$this->service->expects($this->once())->method('createAdminHandover')->with('s1', 'admin', 'admin')
			->willReturn(self::delegation(id: 'd3', delegatedTo: 'admin'));

		$response = $this->controller(uid: 'admin')->handover(secretId: 's1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('admin', $response->getData()['delegatedTo']);
	}//end testAHandoverIsToTheCaller()

	/**
	 * A refused handover is a 403.
	 *
	 * @return void
	 */
	public function testARefusedHandoverIs403(): void {
		$this->service->method('createAdminHandover')->willThrowException(new InvalidArgumentException('Not an admin'));

		$response = $this->controller()->handover(secretId: 's1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testARefusedHandoverIs403()

	/**
	 * Capabilities answer whether the caller may hand over.
	 *
	 * @return void
	 */
	public function testCapabilitiesAnswerForTheCaller(): void {
		$this->service->expects($this->once())->method('canHandover')->with('alice')->willReturn(true);

		$this->assertSame(['canHandover' => true], $this->controller()->capabilities()->getData());
	}//end testCapabilitiesAnswerForTheCaller()

	/**
	 * Anonymous callers are refused with 401 everywhere, before the service.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAre401(): void {
		$this->service->expects($this->never())->method($this->anything());
		$controller = $this->controller(uid: null);

		$responses = [
			$controller->index(secretId: 's1'),
			$controller->create(secretId: 's1', delegatedTo: 'bob'),
			$controller->handover(secretId: 's1'),
			$controller->capabilities(),
		];
		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
	}//end testAnonymousCallersAre401()
}//end class
