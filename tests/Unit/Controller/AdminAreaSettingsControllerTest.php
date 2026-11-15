<?php

/**
 * Outcome tests for AdminAreaSettingsController.
 *
 * Each admin area reads and writes its own settings area; an invalid write
 * answers 400, and only a successful general write that carried the breach
 * check toggle is reported to the connection reporter.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\AdminAreaSettingsController;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\AdminAreaSettingsController
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class AdminAreaSettingsControllerTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked connection reporter.
	 *
	 * @var ConnectionReporter&MockObject
	 */
	private ConnectionReporter&MockObject $reporter;

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->reporter = $this->createMock(ConnectionReporter::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return AdminAreaSettingsController
	 */
	private function controller(): AdminAreaSettingsController {
		return new AdminAreaSettingsController(
			request: $this->request,
			settingsService: $this->settings,
			connectionReporter: $this->reporter,
		);
	}//end controller()

	/**
	 * Every getter reads its own area.
	 *
	 * @return void
	 */
	public function testEveryGetterReadsItsOwnArea(): void {
		$this->settings->method('getAreaSettings')->willReturnCallback(
			static fn (string $area): array => ['area' => $area]
		);
		$controller = $this->controller();

		$this->assertSame(['area' => 'general'], $controller->getGeneralSettings()->getData());
		$this->assertSame(['area' => 'policies'], $controller->getPolicySettings()->getData());
		$this->assertSame(['area' => 'applications'], $controller->getApplicationSettings()->getData());
		$this->assertSame(['area' => 'audit'], $controller->getAuditSettings()->getData());
	}//end testEveryGetterReadsItsOwnArea()

	/**
	 * Every updater writes the request parameters to its own area and
	 * answers the service's result.
	 *
	 * @return void
	 */
	public function testEveryUpdaterWritesTheRequestToItsOwnArea(): void {
		$this->request->method('getParams')->willReturn(['k' => 'v']);
		$written = [];
		$this->settings->method('updateAreaSettings')->willReturnCallback(
			static function (string $area, array $data) use (&$written): array {
				$written[] = [$area, $data];
				return ['saved' => $area];
			}
		);
		$controller = $this->controller();

		$this->assertSame(['saved' => 'policies'], $controller->updatePolicySettings()->getData());
		$this->assertSame(['saved' => 'applications'], $controller->updateApplicationSettings()->getData());
		$this->assertSame(['saved' => 'audit'], $controller->updateAuditSettings()->getData());
		$this->assertSame(
			[['policies', ['k' => 'v']], ['applications', ['k' => 'v']], ['audit', ['k' => 'v']]],
			$written
		);
	}//end testEveryUpdaterWritesTheRequestToItsOwnArea()

	/**
	 * An invalid write answers 400 with the service's message.
	 *
	 * @return void
	 */
	public function testAnInvalidWriteAnswers400(): void {
		$this->request->method('getParams')->willReturn(['k' => 'bad']);
		$this->settings->method('updateAreaSettings')->willThrowException(new InvalidArgumentException('Bad value for k'));

		$response = $this->controller()->updateAuditSettings();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Bad value for k'], $response->getData());
	}//end testAnInvalidWriteAnswers400()

	/**
	 * A successful general write carrying the breach toggle is reported.
	 *
	 * @return void
	 */
	public function testASavedBreachToggleIsReported(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => true]);
		$this->settings->expects($this->once())->method('updateAreaSettings')
			->with('general', ['breach_check_enabled' => true])
			->willReturn(['breach_check_enabled' => true]);
		$this->reporter->expects($this->once())->method('breachCheckSaved');

		$response = $this->controller()->updateGeneralSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testASavedBreachToggleIsReported()

	/**
	 * A general write without the toggle, or a refused one, is not reported.
	 *
	 * @return void
	 */
	public function testAWriteWithoutTheToggleOrARefusedOneIsNotReported(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['other' => 1],
			['breach_check_enabled' => true],
		);
		$this->settings->method('updateAreaSettings')->willReturnOnConsecutiveCalls(
			['other' => 1],
			$this->throwException(new InvalidArgumentException('Refused')),
		);
		$this->reporter->expects($this->never())->method('breachCheckSaved');

		$withoutToggle = $this->controller()->updateGeneralSettings();
		$refused = $this->controller()->updateGeneralSettings();

		$this->assertSame(Http::STATUS_OK, $withoutToggle->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
	}//end testAWriteWithoutTheToggleOrARefusedOneIsNotReported()

	/**
	 * Without a connection reporter a toggled write still succeeds.
	 *
	 * @return void
	 */
	public function testAToggledWriteSucceedsWithoutAReporter(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => false]);
		$this->settings->method('updateAreaSettings')->willReturn(['breach_check_enabled' => false]);
		$controller = new AdminAreaSettingsController(request: $this->request, settingsService: $this->settings);

		$response = $controller->updateGeneralSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['breach_check_enabled' => false], $response->getData());
	}//end testAToggledWriteSucceedsWithoutAReporter()
}//end class
