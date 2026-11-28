<?php

/**
 * Privileged-copy refusal test for SecretVersionController::show().
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

use OCA\Keepiq\Controller\SecretVersionController;
use OCA\Keepiq\Exception\PrivilegedAccessRequiredException;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A version of a privileged copy read outside its access window is a 403
 * carrying the code the client uses to offer an access request.
 *
 * @covers \OCA\Keepiq\Controller\SecretVersionController
 * @uses   \OCA\Keepiq\Exception\ForbiddenException
 * @uses   \OCA\Keepiq\Exception\PrivilegedAccessRequiredException
 */
class SecretVersionControllerPrivilegedTest extends TestCase {

	/**
	 * Show answers 403 with the message and the privileged-access code.
	 *
	 * @return void
	 */
	public function testShowOfAPrivilegedCopyOutsideItsWindowCarriesTheCode(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(SecretVersionService::class);
		$service->expects($this->once())
			->method('getVersion')
			->with('v-1', 'alice')
			->willThrowException(new PrivilegedAccessRequiredException(message: 'Access window required'));

		$controller = new SecretVersionController(
			request: $this->createMock(IRequest::class),
			versionService: $service,
			userSession: $session,
		);

		$response = $controller->show(id: 'v-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['message' => 'Access window required', 'code' => 'privileged_access_required'],
			$response->getData()
		);
	}//end testShowOfAPrivilegedCopyOutsideItsWindowCarriesTheCode()
}//end class
