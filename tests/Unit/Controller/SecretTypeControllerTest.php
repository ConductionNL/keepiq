<?php

/**
 * Unit tests for SecretTypeController with the field list (admin-secret-types).
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

use OCA\Keepiq\Tests\Support\AdminAreaFixture;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Controller\SecretTypeController;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The create and update routes take and return the field list, through the
 * real SecretTypeService.
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
class SecretTypeControllerTest extends TestCase {
	use AdminAreaFixture;


	/**
	 * Build the controller for a user who is or is not an administrator.
	 *
	 * @param bool $isAdmin Whether the session user is an administrator
	 *
	 * @return SecretTypeController
	 */
	private function controllerFor(bool $isAdmin): SecretTypeController {
		$mapper = $this->createMock(SecretTypeMapper::class);
		$mapper->method('countByName')->willReturn(0);
		$service = new SecretTypeService(
			mapper: $mapper,
			secretMapper: $this->createMock(SecretMapper::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($isAdmin === true ? 'admin' : 'bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new SecretTypeController(
			request: $this->createMock(IRequest::class),
			typeService: $service,
			userSession: $session,
			areas: $this->areaAuthorizer(groupManager: $groups),
		);
	}//end controllerFor()

	/**
	 * An administrator creates Server access with its fields; they come back in order.
	 *
	 * @return void
	 */
	public function testAdminCreatesTypeWithFields(): void {
		$response = $this->controllerFor(isAdmin: true)->create(
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			fields: [
				['key' => 'host', 'label' => 'Host', 'kind' => 'url', 'required' => true],
				['key' => 'port', 'label' => 'Port', 'kind' => 'text'],
			],
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('global', $data['scope']);
		$this->assertSame(['host', 'port'], array_column($data['fields'], 'key'));
		$this->assertTrue($data['fields'][0]['required']);
	}//end testAdminCreatesTypeWithFields()

	/**
	 * A regular user posting a global type gets 403.
	 *
	 * @return void
	 */
	public function testRegularUserGetsForbiddenForGlobalType(): void {
		$response = $this->controllerFor(isAdmin: false)->create(
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			fields: [['key' => 'host', 'label' => 'Host', 'kind' => 'url']],
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testRegularUserGetsForbiddenForGlobalType()

	/**
	 * An invalid field list is a 400, not a 500.
	 *
	 * @return void
	 */
	public function testInvalidFieldListIsBadRequest(): void {
		$response = $this->controllerFor(isAdmin: true)->create(
			name: 'odd',
			label: 'Odd',
			scope: 'global',
			fields: [['key' => 'x', 'label' => 'X', 'kind' => 'totp']],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testInvalidFieldListIsBadRequest()

	/**
	 * A holder of the General area who is no instance admin may create a
	 * global type (admin-scoped-roles §2.3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.3
	 */
	public function testAGeneralAreaHolderCreatesAGlobalType(): void {
		$this->delegatedAreas = [AdminSettings::class];

		$response = $this->controllerFor(isAdmin: false)->create(
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			fields: [['key' => 'host', 'label' => 'Host', 'kind' => 'url']],
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testAGeneralAreaHolderCreatesAGlobalType()

	/**
	 * A holder of only the Audit area gets 403 for a global type
	 * (admin-scoped-roles §2.3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.3
	 */
	public function testAnAuditAreaHolderGetsForbiddenForAGlobalType(): void {
		$this->delegatedAreas = [AuditAdminSettings::class];

		$response = $this->controllerFor(isAdmin: false)->create(
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			fields: [['key' => 'host', 'label' => 'Host', 'kind' => 'url']],
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnAuditAreaHolderGetsForbiddenForAGlobalType()
}//end class
