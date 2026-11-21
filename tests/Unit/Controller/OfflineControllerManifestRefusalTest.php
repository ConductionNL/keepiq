<?php

/**
 * Unit tests for the refusal paths of OfflineController::manifest().
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

use OCA\Keepiq\Controller\OfflineController;
use OCA\Keepiq\Kernel\Contracts\OfflineManifestServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * No manifest without a session, and a 404 when the user has no active suite.
 */
#[CoversClass(OfflineController::class)]
class OfflineControllerManifestRefusalTest extends TestCase {
	/** @var OfflineManifestServiceInterface&MockObject */
	private OfflineManifestServiceInterface $manifests;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->manifests = $this->createMock(OfflineManifestServiceInterface::class);
	}//end setUp()

	/**
	 * The controller with offline caching enabled, over the given session user.
	 *
	 * @param string|null $uid The signed-in user id
	 *
	 * @return OfflineController
	 */
	private function controller(?string $uid): OfflineController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturn(true);

		return new OfflineController(
			request: $this->createStub(IRequest::class),
			manifestService: $this->manifests,
			appConfig: $config,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Without a session the manifest is refused with 403 and never built.
	 *
	 * @return void
	 */
	public function testManifestRefusesAnonymous(): void {
		$this->manifests->expects($this->never())->method('buildForUser');

		$response = $this->controller(uid: null)->manifest();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Unauthenticated'], $response->getData());
	}//end testManifestRefusesAnonymous()

	/**
	 * A user without an active encryption suite gets a 404.
	 *
	 * @return void
	 */
	public function testManifestWithoutActiveSuiteIsNotFound(): void {
		$this->manifests->expects($this->once())
			->method('buildForUser')
			->with('bob')
			->willThrowException(new DoesNotExistException('no suite'));

		$response = $this->controller(uid: 'bob')->manifest();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'No active encryption suite'], $response->getData());
	}//end testManifestWithoutActiveSuiteIsNotFound()
}//end class
