<?php

/**
 * Unit tests for LinkShareController without a signed-in user.
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

use OCA\Keepiq\Controller\LinkShareController;
use OCA\Keepiq\Kernel\Contracts\EncryptionSuiteServiceInterface;
use OCA\Keepiq\Kernel\Contracts\LinkShareServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Listing, creating and deleting link shares each answer 401 to an
 * anonymous caller before any share or suite is looked up.
 *
 * @covers \OCA\Keepiq\Controller\LinkShareController
 */
class LinkShareControllerAnonymousTest extends TestCase {

	/**
	 * Index, create and destroy answer 401 and ask neither service anything.
	 *
	 * @return void
	 */
	public function testEveryEndpointAnswers401WithoutAUser(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$shares = $this->createMock(LinkShareServiceInterface::class);
		$shares->expects($this->never())->method($this->anything());
		$suites = $this->createMock(EncryptionSuiteServiceInterface::class);
		$suites->expects($this->never())->method($this->anything());

		$controller = new LinkShareController(
			request: $this->createMock(IRequest::class),
			linkShareService: $shares,
			suiteService: $suites,
			userSession: $session,
			urlGenerator: $this->createMock(IURLGenerator::class),
		);

		$responses = [
			'index' => $controller->index(secretId: 's-1'),
			'create' => $controller->create(secretId: 's-1', encryptedSecretSnapshot: 'SNAP', argon2idSalt: 'SALT'),
			'destroy' => $controller->destroy(id: 'ls-1'),
		];

		foreach ($responses as $action => $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $action);
			$this->assertSame(['message' => 'Unauthorized'], $response->getData(), $action);
		}
	}//end testEveryEndpointAnswers401WithoutAUser()
}//end class
