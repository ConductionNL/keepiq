<?php

/**
 * Unit tests for AuditController without a signed-in user.
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

use OCA\Keepiq\Controller\AuditController;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Kernel\Contracts\AuditServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecentlyUsedServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every user-scoped audit endpoint answers 401 and reads nothing when no user is signed in.
 */
#[CoversClass(AuditController::class)]
class AuditControllerUnauthenticatedTest extends TestCase {

	/**
	 * The controller with an empty session; no collaborator may be read.
	 *
	 * @return AuditController
	 */
	private function controller(): AuditController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$audit = $this->createMock(AuditServiceInterface::class);
		$audit->expects($this->never())->method('listForObject');
		$audit->expects($this->never())->method('listForActor');
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->expects($this->never())->method('findById');
		$recent = $this->createMock(RecentlyUsedServiceInterface::class);
		$recent->expects($this->never())->method('forUser');

		return new AuditController(
			request: $this->createStub(IRequest::class),
			auditService: $audit,
			secretMapper: $secrets,
			userSession: $session,
			recentlyUsed: $recent,
		);
	}//end controller()

	/**
	 * A secret's trail needs a session.
	 *
	 * @return void
	 */
	public function testSecretTrailRefusesAnonymous(): void {
		$response = $this->controller()->secret(id: 'sec-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testSecretTrailRefusesAnonymous()

	/**
	 * The personal activity list needs a session.
	 *
	 * @return void
	 */
	public function testMineRefusesAnonymous(): void {
		$response = $this->controller()->mine(page: 2, limit: 10);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testMineRefusesAnonymous()

	/**
	 * The recently-used widget needs a session.
	 *
	 * @return void
	 */
	public function testRecentRefusesAnonymous(): void {
		$response = $this->controller()->recent();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testRecentRefusesAnonymous()
}//end class
