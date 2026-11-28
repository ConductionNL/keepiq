<?php

/**
 * Unit tests for the status mapping of FederationController.
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

use OCA\Keepiq\Controller\FederationController;
use OCA\Keepiq\Kernel\Contracts\FederatedCertificateServiceInterface;
use OCA\Keepiq\Service\PartnerCertificateBatchService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Federation being switched off is a 409, other partner failures a 502; status needs a session.
 */
#[CoversClass(FederationController::class)]
class FederationControllerStatusMappingTest extends TestCase {
	/** @var FederatedCertificateServiceInterface&MockObject */
	private FederatedCertificateServiceInterface $certificates;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->certificates = $this->createMock(FederatedCertificateServiceInterface::class);
	}//end setUp()

	/**
	 * The controller over the given session user, or none.
	 *
	 * @param string|null $uid The signed-in user id
	 *
	 * @return FederationController
	 */
	private function controller(?string $uid): FederationController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederationController(
			request: $this->createStub(IRequest::class),
			certificates: $this->certificates,
			userSession: $session,
			batch: $this->createStub(originalClassName: PartnerCertificateBatchService::class),
		);
	}//end controller()

	/**
	 * Federation disabled on this instance is a 409, not a 502.
	 *
	 * @return void
	 */
	public function testFederationUnavailableIsConflict(): void {
		$this->certificates->expects($this->once())
			->method('lookup')
			->with('bob@partner.example', 'alice')
			->willThrowException(new RuntimeException('federation_unavailable'));

		$response = $this->controller(uid: 'alice')->recipientCertificate(cloudId: 'bob@partner.example');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'federation_unavailable'], $response->getData());
	}//end testFederationUnavailableIsConflict()

	/**
	 * Any other partner failure stays a 502.
	 *
	 * @return void
	 */
	public function testOtherPartnerFailureIsBadGateway(): void {
		$this->certificates->method('lookup')->willThrowException(new RuntimeException('partner_timeout'));

		$response = $this->controller(uid: 'alice')->recipientCertificate(cloudId: 'bob@partner.example');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'partner_timeout'], $response->getData());
	}//end testOtherPartnerFailureIsBadGateway()

	/**
	 * The outbound status needs a session and asks nothing without one.
	 *
	 * @return void
	 */
	public function testStatusRefusesAnonymous(): void {
		$this->certificates->expects($this->never())->method('outboundAvailable');

		$response = $this->controller(uid: null)->status();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testStatusRefusesAnonymous()
}//end class
