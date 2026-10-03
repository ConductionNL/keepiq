<?php

/**
 * Contract tests for GET /api/v1/app/certificate (app-own-certificate).
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

use OCA\Keepiq\Controller\ApplicationApiController;
use OCA\Keepiq\Controller\ApplicationCertificateController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The calling application gets its own certificate and nothing else.
 */
class ApplicationCertificateControllerTest extends TestCase {
	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	/** @var MachineSecretEnvelopeService&MockObject */
	private MachineSecretEnvelopeService $envelopes;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->envelopes = $this->createMock(MachineSecretEnvelopeService::class);
	}//end setUp()

	/**
	 * The controller, with the application JwtAuthMiddleware would bind.
	 *
	 * @param string|null $applicationId The bound application, null for none
	 *
	 * @return ApplicationCertificateController
	 */
	private function controller(?string $applicationId): ApplicationCertificateController {
		$controller = new ApplicationCertificateController(
			request: $this->createStub(IRequest::class),
			suites: $this->suites,
			envelopes: $this->envelopes,
		);
		if ($applicationId !== null) {
			$application = new Application();
			$application->setId($applicationId);
			$controller->setApplication($application);
		}

		return $controller;
	}//end controller()

	/**
	 * The application gets its active suite's certificate and the same
	 * fingerprint the envelopes carry, and no private key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-own-certificate/tasks.md#1.1
	 */
	public function testTheApplicationGetsItsOwnCertificate(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-7');
		$suite->setCertificate('-----BEGIN CERTIFICATE-----');
		$suite->setPrivateKey('ENCRYPTED-PRIVATE-KEY');
		$this->suites->expects($this->once())->method('findActiveByOwner')->with('application', 'app-1')->willReturn($suite);
		$this->envelopes->expects($this->once())->method('certificateFingerprint')->with('suite-7')->willReturn('sha256:abc');

		$response = $this->controller(applicationId: 'app-1')->show();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['applicationId' => 'app-1', 'suiteId' => 'suite-7', 'certificate' => '-----BEGIN CERTIFICATE-----', 'certificateFingerprint' => 'sha256:abc'],
			$response->getData()
		);
		$this->assertStringNotContainsString('PRIVATE', (string)json_encode($response->getData()));
	}//end testTheApplicationGetsItsOwnCertificate()

	/**
	 * Without a bound application the answer is 401 and no suite is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-own-certificate/tasks.md#1.1
	 */
	public function testNoTokenIs401(): void {
		$this->suites->expects($this->never())->method('findActiveByOwner');

		$this->assertSame(401, $this->controller(applicationId: null)->show()->getStatus());
	}//end testNoTokenIs401()

	/**
	 * An application without an active suite gets 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-own-certificate/tasks.md#1.1
	 */
	public function testNoActiveSuiteIs404(): void {
		$this->suites->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));

		$this->assertSame(404, $this->controller(applicationId: 'app-1')->show()->getStatus());
	}//end testNoActiveSuiteIs404()

	/**
	 * The route is on the Bearer surface: JwtAuthMiddleware only binds an
	 * application for ApplicationApiController subclasses, and no session
	 * user reaches it through NoAdminRequired.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-own-certificate/tasks.md#1.1
	 */
	public function testTheRouteIsOnTheBearerSurface(): void {
		$method = new ReflectionMethod(ApplicationCertificateController::class, 'show');

		$this->assertTrue(is_subclass_of(ApplicationCertificateController::class, ApplicationApiController::class));
		$this->assertNotSame([], $method->getAttributes(PublicPage::class));
		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
	}//end testTheRouteIsOnTheBearerSurface()
}//end class
