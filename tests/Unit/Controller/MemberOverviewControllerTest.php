<?php

/**
 * Guard tests for MemberOverviewController (admin-member-overview-and-offboarding §2.2).
 *
 * @category Tests
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

use OCA\Keepiq\Controller\MemberOverviewController;
use OCA\Keepiq\Service\MemberOverviewService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The member list is refused to a regular user.
 *
 * Nextcloud's SecurityMiddleware refuses a non-admin before the controller
 * body runs, and it decides from the DISPATCHED method's attributes. So the
 * refusal for a regular user is exactly: the method carries
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` and nothing that opens
 * it wider (`NoAdminRequired`, `PublicPage`, `SubAdminRequired`).
 */
class MemberOverviewControllerTest extends TestCase {
	/**
	 * A regular user is refused: admin-setting guard, no widening attribute.
	 *
	 * @return void
	 */
	public function testRegularUserIsRefusedByTheAdminGuard(): void {
		$method = new ReflectionMethod(MemberOverviewController::class, 'index');

		$guards = $method->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $guards);
		$this->assertSame(AdminSettings::class, $guards[0]->newInstance()->getSettings());

		foreach ([NoAdminRequired::class, PublicPage::class, SubAdminRequired::class] as $widening) {
			$this->assertSame([], $method->getAttributes($widening), $widening . ' would let a regular user in');
		}
	}//end testRegularUserIsRefusedByTheAdminGuard()

	/**
	 * The route exists and points at this method.
	 *
	 * @return void
	 */
	public function testRouteIsRegistered(): void {
		$routes = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$this->assertMatchesRegularExpression(
			"#'memberOverview\\#index',\\s*'url' => '/api/v1/admin/members',\\s*'verb' => 'GET'#",
			(string)$routes
		);
	}//end testRouteIsRegistered()

	/**
	 * A bad filter answers 400, not 500.
	 *
	 * @return void
	 */
	public function testBadFilterAnswersBadRequest(): void {
		$controller = new MemberOverviewController(
			request: $this->createMock(originalClassName: IRequest::class),
			service: new MemberOverviewService(
				userManager: $this->createMock(originalClassName: \OCP\IUserManager::class),
				suiteMapper: $this->createMock(originalClassName: \OCA\Keepiq\Db\EncryptionSuiteMapper::class),
				secretMapper: $this->createMock(originalClassName: \OCA\Keepiq\Db\SecretMapper::class),
				memberMapper: $this->createMock(originalClassName: \OCA\Keepiq\Db\TeamFolderMemberMapper::class),
				contactMapper: $this->createMock(originalClassName: \OCA\Keepiq\Db\EmergencyContactMapper::class),
			),
		);

		$response = $controller->index(status: 'bogus');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testBadFilterAnswersBadRequest()
}//end class
