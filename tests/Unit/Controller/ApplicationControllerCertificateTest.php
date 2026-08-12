<?php

/**
 * Unit tests for the ApplicationController certificate endpoint
 * (GET /api/v1/applications/{id}/certificate).
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Doriath\Controller\ApplicationController;
use OCA\Doriath\Service\ApplicationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `application#certificate`.
 *
 * The route hands the controller the application id from the URL; the ITEM is
 * that this exact id reaches ApplicationService::getCertificate() and that the
 * PEM the service reports back is the PEM the caller receives. The three
 * failure branches the method actually has — anonymous session, a service-level
 * InvalidArgumentException (unknown/inactive application) and a null
 * certificate (no active EncryptionSuite) — each map to their own status code.
 *
 */
class ApplicationControllerCertificateTest extends TestCase {

	/**
	 * The mocked application service.
	 *
	 * @var ApplicationService&MockObject
	 */
	private ApplicationService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(ApplicationService::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller under test with its collaborators mocked.
	 *
	 * @param string|null $userId The session uid, or null for an anonymous caller.
	 *
	 * @return ApplicationController The controller under test.
	 */
	private function controller(?string $userId = 'alice'): ApplicationController {
		if ($userId === null) {
			$this->session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$this->session->method('getUser')->willReturn($user);
		}

		return new ApplicationController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			session: $this->session,
			groupManager: $this->createMock(IGroupManager::class),
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end controller()

	/**
	 * The happy path: the route id is forwarded verbatim and the service's PEM
	 * is returned alongside it.
	 *
	 * @return void
	 */
	public function testCertificateForwardsTheRouteIdAndReturnsTheServicePem(): void {
		$applicationId = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
		$pem = "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n";

		// The ITEM: the id from the URL is the id the service is asked about.
		$this->service->expects($this->once())
			->method('getCertificate')
			->with($applicationId)
			->willReturn($pem);

		$response = $this->controller()->certificate($applicationId);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'id' => $applicationId,
				'certificate' => $pem,
			],
			$response->getData()
		);
	}//end testCertificateForwardsTheRouteIdAndReturnsTheServicePem()

	/**
	 * An anonymous caller is rejected with 401 and the service is never asked
	 * about the application at all — no existence oracle for the unauthenticated.
	 *
	 * @return void
	 */
	public function testCertificateRejectsAnAnonymousCallerWithoutTouchingTheService(): void {
		$this->service->expects($this->never())->method('getCertificate');

		$response = $this->controller(userId: null)->certificate('f47ac10b-58cc-4372-a567-0e02b2c3d479');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testCertificateRejectsAnAnonymousCallerWithoutTouchingTheService()

	/**
	 * A service-level InvalidArgumentException (unknown or inactive
	 * application) becomes a 400 carrying the service's own message.
	 *
	 * @return void
	 */
	public function testCertificateTranslatesAnInvalidApplicationIntoBadRequest(): void {
		$this->service->expects($this->once())
			->method('getCertificate')
			->with('not-a-known-application')
			->willThrowException(new InvalidArgumentException(message: 'Application is not active'));

		$response = $this->controller()->certificate('not-a-known-application');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Application is not active'], $response->getData());
	}//end testCertificateTranslatesAnInvalidApplicationIntoBadRequest()

	/**
	 * An active application with no active EncryptionSuite yields a null
	 * certificate, which the endpoint reports as 404 — not as an empty 200.
	 *
	 * @return void
	 */
	public function testCertificateReturnsNotFoundWhenNoActiveEncryptionSuiteExists(): void {
		$applicationId = 'a3bb189e-8bf9-3888-9912-ace4e6543002';

		$this->service->expects($this->once())
			->method('getCertificate')
			->with($applicationId)
			->willReturn(null);

		$response = $this->controller()->certificate($applicationId);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(
			['message' => 'No active EncryptionSuite for this application'],
			$response->getData()
		);
	}//end testCertificateReturnsNotFoundWhenNoActiveEncryptionSuiteExists()

}//end class
