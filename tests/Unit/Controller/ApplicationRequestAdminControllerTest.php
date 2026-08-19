<?php

/**
 * Doriath - ApplicationRequestAdminController tests
 *
 * This controller carries `#[NoAdminRequired]` while being admin-only, which is
 * a deliberate pairing rather than a mistake: the annotation lets the request
 * reach the method so a non-administrator receives a JSON 403 the admin UI can
 * render, instead of a login redirect arriving where fetch() expected JSON.
 *
 * That pairing is precisely the kind that has gone wrong before — an endpoint
 * satisfying the route-auth gate with an annotation whose body does not back it
 * up. So the refusal is asserted here at the controller, not only in the service.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Doriath\Controller\ApplicationRequestAdminController;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Service\ApplicationRequestAdminService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the admin-scoped application-request surface.
 */
class ApplicationRequestAdminControllerTest extends TestCase {
	/**
	 * The service mock.
	 *
	 * @var ApplicationRequestAdminService&MockObject
	 */
	private ApplicationRequestAdminService&MockObject $service;

	/**
	 * The group manager mock, which decides admin-ness.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Wire a controller for a given caller.
	 *
	 * @param string|null $uid The caller's uid, or null for anonymous
	 * @param bool $isAdmin Whether that caller is an administrator
	 *
	 * @return ApplicationRequestAdminController
	 */
	private function controllerFor(?string $uid, bool $isAdmin): ApplicationRequestAdminController {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$this->groupManager->method('isAdmin')->willReturn($isAdmin);

		return new ApplicationRequestAdminController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			userSession: $session,
			groupManager: $this->groupManager,
		);
	}//end controllerFor()

	/**
	 * Build a request row the service can return.
	 *
	 * @return SecretRequest
	 */
	private function row(): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setToken('tok-1');
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy(SecretRequest::actorForApplication('app-1'));

		return $entity;
	}//end row()

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(ApplicationRequestAdminService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * An administrator gets the application's requests.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testAnAdministratorSeesTheApplicationsRequests(): void {
		$this->service->expects($this->once())
			->method('listForApplication')
			->with('app-1', true)
			->willReturn([$this->row()]);

		$response = $this->controllerFor('admin', true)->index(id: 'app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}//end testAnAdministratorSeesTheApplicationsRequests()

	/**
	 * A non-administrator is refused, and the service is never consulted.
	 *
	 * Refused regardless of who registered the application — there is no registrar
	 * input to this decision at all, which is the point.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testANonAdministratorIsRefused(): void {
		$this->service->expects($this->never())->method('listForApplication');

		$response = $this->controllerFor('alice', false)->index(id: 'app-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testANonAdministratorIsRefused()

	/**
	 * An anonymous caller is refused too.
	 *
	 * `#[NoAdminRequired]` does not mean "no session required", but asserting it
	 * costs one test and the alternative is a null-user path nobody exercised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->service->expects($this->never())->method('listForApplication');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controllerFor(null, false)->index(id: 'app-1')->getStatus()
		);
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A revoke passes the acting administrator through to the service.
	 *
	 * The uid matters: the audit event records who ended the link, and recording
	 * the application or a placeholder would make the trail useless for the one
	 * question it exists to answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testARevokeRecordsTheActingAdministrator(): void {
		$this->service->expects($this->once())
			->method('revokeForApplication')
			->with('req-1', 'app-1', 'admin', true)
			->willReturn($this->row());

		$response = $this->controllerFor('admin', true)->destroy(id: 'app-1', requestId: 'req-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testARevokeRecordsTheActingAdministrator()

	/**
	 * A non-administrator cannot revoke.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testANonAdministratorCannotRevoke(): void {
		$this->service->expects($this->never())->method('revokeForApplication');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controllerFor('alice', false)->destroy(id: 'app-1', requestId: 'req-1')->getStatus()
		);
	}//end testANonAdministratorCannotRevoke()

	/**
	 * The service's own status code survives to the response.
	 *
	 * A request belonging to another actor answers 404, and that must not be
	 * flattened into a generic 400 — the code is what tells the caller whether to
	 * fix the id or stop asking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testTheServicesRefusalCodeIsPreserved(): void {
		$this->service->method('revokeForApplication')->willThrowException(
			new InvalidArgumentException(message: 'Request not found for this application', code: 404)
		);

		$response = $this->controllerFor('admin', true)->destroy(id: 'app-1', requestId: 'req-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testTheServicesRefusalCodeIsPreserved()
}//end class
