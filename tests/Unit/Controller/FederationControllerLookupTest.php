<?php

/**
 * Unit tests for FederationController's lookup refusals and partner status.
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

use InvalidArgumentException;
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

/**
 * A recipient outside the allowlist is 403, an unknown one 404, an
 * anonymous caller 401; status reports whether any outbound partner exists.
 */
#[CoversClass(FederationController::class)]
class FederationControllerLookupTest extends TestCase {
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
			batch: $this->createStub(PartnerCertificateBatchService::class),
		);
	}//end controller()

	/**
	 * An anonymous lookup is 401 and no partner is asked.
	 *
	 * @return void
	 */
	public function testLookupWithoutAUserIsUnauthorized(): void {
		$this->certificates->expects($this->never())->method('lookup');

		$response = $this->controller(null)->recipientCertificate(cloudId: 'bob@partner.example');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testLookupWithoutAUserIsUnauthorized()

	/**
	 * A recipient whose instance is not a partner is 403.
	 *
	 * @return void
	 */
	public function testNotAPartnerIsForbidden(): void {
		$this->certificates->expects($this->once())
			->method('lookup')
			->with('bob@other.example', 'alice')
			->willThrowException(new InvalidArgumentException('not_a_partner'));

		$response = $this->controller('alice')->recipientCertificate(cloudId: 'bob@other.example');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'not_a_partner'], $response->getData());
	}//end testNotAPartnerIsForbidden()

	/**
	 * Any other invalid lookup, such as an unknown recipient, is 404.
	 *
	 * @return void
	 */
	public function testUnknownRecipientIsNotFound(): void {
		$this->certificates->method('lookup')->willThrowException(new InvalidArgumentException('unknown_recipient'));

		$response = $this->controller('alice')->recipientCertificate(cloudId: 'nobody@partner.example');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'unknown_recipient'], $response->getData());
	}//end testUnknownRecipientIsNotFound()

	/**
	 * Status reports the outbound availability for a signed-in user.
	 *
	 * @return void
	 */
	public function testStatusReportsOutboundAvailability(): void {
		$this->certificates->method('outboundAvailable')->willReturnOnConsecutiveCalls(true, false);

		$this->assertSame(['outbound' => true], $this->controller('alice')->status()->getData());
		$this->assertSame(['outbound' => false], $this->controller('alice')->status()->getData());
	}//end testStatusReportsOutboundAvailability()
}//end class
