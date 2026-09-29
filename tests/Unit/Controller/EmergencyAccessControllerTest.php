<?php

/**
 * Unit tests for EmergencyAccessController's contact lists.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\EmergencyAccessController;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Service\EmergencyAccessService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmergencyAccessController.
 */
class EmergencyAccessControllerTest extends TestCase {
	/**
	 * A controller for $userId over a service returning $contact from both lists.
	 *
	 * @param string $userId The signed-in user
	 * @param EmergencyContact $contact The contact both lists return
	 *
	 * @return EmergencyAccessController
	 */
	private function controller(string $userId, EmergencyContact $contact): EmergencyAccessController {
		$service = $this->createMock(EmergencyAccessService::class);
		$service->method('listForGrantor')->willReturn([$contact]);
		$service->method('listForGrantee')->willReturn([$contact]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new EmergencyAccessController(
			request: $this->createMock(IRequest::class),
			service: $service,
			userSession: $userSession,
		);
	}//end controller()

	/**
	 * A contact a rotation did not carry.
	 *
	 * @return EmergencyContact
	 */
	private function notCarried(): EmergencyContact {
		$contact = new EmergencyContact();
		$contact->setId('rel-1');
		$contact->setGrantorUserId('alice');
		$contact->setGranteeUserId('bob');
		$contact->setState(EmergencyContact::STATE_INVALIDATED);
		$contact->setInvalidatedReason('grantor_rotation_not_carried');
		return $contact;
	}//end notCarried()

	/**
	 * The grantor's list says why a contact was invalidated.
	 *
	 * @return void
	 */
	public function testIndexCarriesTheInvalidationReason(): void {
		$data = $this->controller(userId: 'alice', contact: $this->notCarried())->index()->getData();

		$this->assertSame('grantor_rotation_not_carried', $data[0]['invalidatedReason']);
	}//end testIndexCarriesTheInvalidationReason()

	/**
	 * The grantee's incoming list does not: that the grantor chose not to carry
	 * them is the grantor's to know.
	 *
	 * @return void
	 */
	public function testIncomingOmitsTheInvalidationReason(): void {
		$data = $this->controller(userId: 'bob', contact: $this->notCarried())->incoming()->getData();

		$this->assertArrayNotHasKey('invalidatedReason', $data[0]);
	}//end testIncomingOmitsTheInvalidationReason()
}//end class
