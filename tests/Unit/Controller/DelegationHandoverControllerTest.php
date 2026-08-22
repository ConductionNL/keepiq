<?php

/**
 * Unit tests for the vault-admin handover endpoint.
 *
 * `DelegationService::createAdminHandover()` was implemented, unit-tested and
 * named by the spec, and had NO production caller — no route, no controller
 * action, no UI. These tests pin the seam that makes it reachable, and they
 * assert on the SERVICE CALL and the status code, not merely that a
 * JSONResponse came back: a controller that returned 201 without ever calling
 * the service would satisfy a shape-only assertion.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\DelegationController;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Service\DelegationService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests the routed handover action and the capabilities read behind it.
 */
class DelegationHandoverControllerTest extends TestCase {
	private DelegationService&MockObject $service;

	private IUserSession&MockObject $userSession;

	/**
	 * Build the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: DelegationService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Sign a user in for the duration of a test.
	 *
	 * @param string|null $uid The UID, or null for an anonymous session.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Build the controller under test.
	 *
	 * @return DelegationController
	 */
	private function controller(): DelegationController {
		return new DelegationController(
			request: $this->createMock(originalClassName: IRequest::class),
			delegationService: $this->service,
			userSession: $this->userSession
		);
	}//end controller()

	/**
	 * The handover reaches the admin path with the CALLER as the delegate.
	 *
	 * `delegatedTo` must equal `initiatedBy`: this path promotes the admin's
	 * own copy. If the controller ever forwarded a caller-supplied delegate,
	 * an admin could hand somebody else's secret to a third party, which the
	 * spec does not allow.
	 *
	 * @return void
	 */
	public function testHandoverPromotesTheCallingAdminAndNotACallerSuppliedDelegate(): void {
		$this->signIn('vaultadmin');
		$entity = new SecretDelegation();

		$this->service->expects($this->once())
			->method('createAdminHandover')
			->with('secret-1', 'vaultadmin', 'vaultadmin')
			->willReturn($entity);

		$response = $this->controller()->handover(secretId: 'secret-1');

		$this->assertSame(201, $response->getStatus());
	}//end testHandoverPromotesTheCallingAdminAndNotACallerSuppliedDelegate()

	/**
	 * The service's refusal reaches the caller as a 403 with its reason.
	 *
	 * The reason matters: "you are not a vault admin" and "this secret is not
	 * shared with you" are different problems with different remedies.
	 *
	 * @return void
	 */
	public function testAServiceRefusalBecomesA403CarryingItsReason(): void {
		$this->signIn('someuser');
		$this->service->method('createAdminHandover')
			->willThrowException(
				new InvalidArgumentException('Admin handover requires membership in the vault_admin group')
			);

		$response = $this->controller()->handover(secretId: 'secret-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			'Admin handover requires membership in the vault_admin group',
			$response->getData()['message']
		);
	}//end testAServiceRefusalBecomesA403CarryingItsReason()

	/**
	 * An anonymous caller never reaches the service.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRejectedBeforeTheService(): void {
		$this->signIn(null);
		$this->service->expects($this->never())->method('createAdminHandover');

		$this->assertSame(401, $this->controller()->handover(secretId: 'secret-1')->getStatus());
	}//end testAnAnonymousCallerIsRejectedBeforeTheService()

	/**
	 * The capabilities read reports group membership for the CALLER.
	 *
	 * @return void
	 */
	public function testCapabilitiesReportsVaultAdminMembershipForTheCaller(): void {
		$this->signIn('vaultadmin');
		$this->service->expects($this->once())
			->method('isVaultAdmin')
			->with('vaultadmin')
			->willReturn(true);

		$response = $this->controller()->capabilities();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['isVaultAdmin' => true], $response->getData());
	}//end testCapabilitiesReportsVaultAdminMembershipForTheCaller()

	/**
	 * A non-admin is told so, rather than being left to guess.
	 *
	 * @return void
	 */
	public function testCapabilitiesReportsFalseForANonAdmin(): void {
		$this->signIn('someuser');
		$this->service->method('isVaultAdmin')->willReturn(false);

		$this->assertSame(['isVaultAdmin' => false], $this->controller()->capabilities()->getData());
	}//end testCapabilitiesReportsFalseForANonAdmin()

	/**
	 * Both new actions declare their auth posture on their own method.
	 *
	 * Nextcloud's middleware reads the DISPATCHED method's attributes, so an
	 * action that inherits nothing is unreachable — the failure mode is a
	 * silent 401 rather than an error, which is why this is asserted rather
	 * than assumed.
	 *
	 * @return void
	 */
	public function testBothNewActionsDeclareTheirAuthPosture(): void {
		$checked = 0;

		foreach (['handover', 'capabilities'] as $method) {
			$attributes = (new ReflectionMethod(DelegationController::class, $method))
				->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class);
			$checked++;
			$this->assertCount(
				1,
				$attributes,
				sprintf('DelegationController::%s() must declare #[NoAdminRequired] itself', $method)
			);
		}

		// Positive control: the loop above is only meaningful if it ran.
		$this->assertSame(2, $checked);
	}//end testBothNewActionsDeclareTheirAuthPosture()
}//end class
