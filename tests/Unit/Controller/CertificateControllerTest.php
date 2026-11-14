<?php

/**
 * Unit tests for CertificateController.
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

use OCA\Keepiq\Controller\CertificateController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The metadata submit maps each outcome to its own status.
 */
class CertificateControllerTest extends TestCase {

	/**
	 * A secret that moves twice while its expiry is set from notAfter
	 * answers 409, not 500.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testASecretThatMovesTwiceDuringTheSubmitAnswers409(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(CertificateLifecycleService::class);
		$service->method('submitMetadata')->willThrowException(new StaleWriteException(current: new Secret()));
		$controller = new CertificateController(
			request: $this->createMock(IRequest::class),
			service: $service,
			userSession: $session,
			areas: $this->createMock(AdminAreaAuthorizer::class),
		);

		$response = $controller->submitMetadata(secretId: 'secret-1', notAfter: '2030-01-01T00:00:00Z');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testASecretThatMovesTwiceDuringTheSubmitAnswers409()
}//end class
