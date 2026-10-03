<?php

/**
 * Contract tests for the admin API index (admin-public-api §1.1).
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

use OCA\Keepiq\Controller\AdminIndexController;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Tests\Support\AdminAreaFixture;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The index answers area holders and nobody else.
 */
class AdminIndexControllerTest extends TestCase {
	use AdminAreaFixture;

	/**
	 * The controller for a caller.
	 *
	 * @param string|null $uid The caller, null for anonymous
	 * @param bool $isAdmin Whether the caller is an instance admin
	 *
	 * @return AdminIndexController
	 */
	private function controller(?string $uid, bool $isAdmin = false): AdminIndexController {
		$session = $this->createStub(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new AdminIndexController(
			request: $this->createStub(IRequest::class),
			userSession: $session,
			areas: $this->areaAuthorizer(groupManager: $groups),
		);
	}//end controller()

	/**
	 * An admin gets version 1, every area and every path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.1
	 */
	public function testAnAdminGetsTheIndex(): void {
		$data = $this->controller(uid: 'root', isAdmin: true)->index()->getData();

		$this->assertSame(1, $data['apiVersion']);
		$this->assertSame(['v1'], $data['versions']);
		$this->assertSame(['general', 'policies', 'applications', 'people', 'audit'], $data['areas']);
		$this->assertSame(AdminIndexController::PATHS, $data['paths']);
	}//end testAnAdminGetsTheIndex()

	/**
	 * An Audit-only service account gets the index with its one area.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.1
	 */
	public function testAnAuditHolderGetsTheIndex(): void {
		$this->delegatedAreas = [AuditAdminSettings::class];
		$response = $this->controller(uid: 'svc-audit')->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['audit'], $response->getData()['areas']);
	}//end testAnAuditHolderGetsTheIndex()

	/**
	 * A user without any area, and an anonymous caller, are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.1
	 */
	public function testNoAreaIsRefused(): void {
		$this->assertSame(403, $this->controller(uid: 'bob')->index()->getStatus());
		$this->assertSame(403, $this->controller(uid: null)->index()->getStatus());
	}//end testNoAreaIsRefused()

	/**
	 * The path list offers neither force revocation nor reinstatement.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.6
	 */
	public function testNoPathRevokesOrReinstatesASuite(): void {
		foreach (AdminIndexController::PATHS as $path) {
			$this->assertStringNotContainsString('force-revoke', $path['path']);
			$this->assertStringNotContainsString('reinstate', $path['path']);
		}
	}//end testNoPathRevokesOrReinstatesASuite()
}//end class
