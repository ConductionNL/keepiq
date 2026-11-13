<?php

/**
 * Unit tests for FederatedShareController without a signed-in user.
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

use OCA\Keepiq\Controller\FederatedShareController;
use OCA\Keepiq\Service\FederatedShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Every federated-share endpoint refuses an anonymous caller before touching a share.
 */
class FederatedShareControllerAuthTest extends TestCase {

	/**
	 * Create, list, update, revoke and suspend each answer 401 and ask the service nothing.
	 *
	 * @return void
	 */
	public function testEveryEndpointAnswers401WithoutAUser(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$shares = $this->createMock(FederatedShareService::class);
		$shares->expects($this->never())->method($this->anything());
		$controller = new FederatedShareController(
			request: $this->createMock(IRequest::class),
			shares: $shares,
			userSession: $session,
		);

		$responses = [
			'create' => $controller->create(secretId: 's-1', recipientCloudId: 'bob@partner.example', key: 'CIPHER'),
			'index' => $controller->index(secretId: 's-1'),
			'update' => $controller->update(id: 'fs-1', key: 'CIPHER'),
			'destroy' => $controller->destroy(id: 'fs-1'),
			'suspend' => $controller->suspend(id: 'fs-1', reason: 'audit'),
		];

		foreach ($responses as $action => $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $action);
			$this->assertSame(['message' => 'Unauthorized'], $response->getData(), $action);
		}
	}//end testEveryEndpointAnswers401WithoutAUser()
}//end class
