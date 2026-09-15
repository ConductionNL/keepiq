<?php

/**
 * SettingsController connection refresh tests.
 *
 * An admin save that writes `breach_check_enabled` asks integriq to resolve
 * the breach check connection again. These tests guard the three ways that
 * could quietly stop: a save that never asks, a refused save that asks anyway,
 * and the hand-built container factory dropping the reporter so the
 * constructor default of null switches the refresh off without a sound.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\DomainOverrideRegistrar;
use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for the connection refresh of SettingsController::updateAdminSettings().
 *
 * @covers \OCA\Keepiq\Controller\SettingsController
 * @covers \OCA\Keepiq\AppInfo\DomainOverrideRegistrar
 */
class SettingsControllerConnectionRefreshTest extends TestCase {

	/**
	 * Mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Mocked connection reporter.
	 *
	 * @var ConnectionReporter&MockObject
	 */
	private ConnectionReporter&MockObject $reporter;

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request         = $this->createMock(originalClassName: IRequest::class);
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->reporter        = $this->createMock(originalClassName: ConnectionReporter::class);
	}//end setUp()

	/**
	 * The controller as the container builds it.
	 *
	 * @param ConnectionReporter|null $reporter The reporter, or null for an instance without one.
	 *
	 * @return SettingsController
	 */
	private function controller(?ConnectionReporter $reporter): SettingsController {
		return new SettingsController(
			request: $this->request,
			settingsService: $this->settingsService,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			connectionReporter: $reporter,
		);
	}//end controller()

	/**
	 * A save that writes the breach check switch asks for a refresh, once.
	 *
	 * @return void
	 */
	public function testSavingTheSwitchAsksForARefresh(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => false]);
		$this->settingsService->method('updateAdminSettings')->willReturn(['breach_check_enabled' => false]);
		$this->reporter->expects($this->once())->method('breachCheckSaved')->willReturn(true);

		$response = $this->controller(reporter: $this->reporter)->updateAdminSettings();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['breach_check_enabled' => false], actual: $response->getData());
	}//end testSavingTheSwitchAsksForARefresh()

	/**
	 * A save that leaves the switch alone asks for nothing.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheSwitchAsksForNothing(): void {
		$this->request->method('getParams')->willReturn(['vault_lock_timeout' => 15]);
		$this->settingsService->method('updateAdminSettings')->willReturn([]);
		$this->reporter->expects($this->never())->method('breachCheckSaved');

		$this->controller(reporter: $this->reporter)->updateAdminSettings();
	}//end testASaveWithoutTheSwitchAsksForNothing()

	/**
	 * A refused save wrote nothing, so it asks for nothing.
	 *
	 * @return void
	 */
	public function testARefusedSaveAsksForNothing(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => true]);
		$this->settingsService->method('updateAdminSettings')->willThrowException(new InvalidArgumentException('bad value'));
		$this->reporter->expects($this->never())->method('breachCheckSaved');

		$response = $this->controller(reporter: $this->reporter)->updateAdminSettings();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testARefusedSaveAsksForNothing()

	/**
	 * Without the reporter the save answers exactly as before.
	 *
	 * @return void
	 */
	public function testWithoutTheReporterTheSaveStillAnswers(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => true]);
		$this->settingsService->method('updateAdminSettings')->willReturn(['breach_check_enabled' => true]);

		$response = $this->controller(reporter: null)->updateAdminSettings();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testWithoutTheReporterTheSaveStillAnswers()

	/**
	 * The hand-built container factory passes the reporter to the controller.
	 *
	 * The controller's own default is null, so a factory that forgets the
	 * argument still builds, and the refresh never goes out.
	 *
	 * @return void
	 */
	public function testTheContainerFactoryPassesTheReporter(): void {
		$factories = [];
		$context   = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new DomainOverrideRegistrar())->register(context: $context);
		$this->assertArrayHasKey(key: SettingsController::class, array: $factories);

		$services = [
			IRequest::class           => $this->request,
			SettingsService::class    => $this->settingsService,
			IUserSession::class       => $this->createMock(originalClassName: IUserSession::class),
			ConnectionReporter::class => $this->reporter,
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $services[$id]
		);

		$this->request->method('getParams')->willReturn(['breach_check_enabled' => true]);
		$this->settingsService->method('updateAdminSettings')->willReturn([]);
		$this->reporter->expects($this->once())->method('breachCheckSaved')->willReturn(true);

		$controller = $factories[SettingsController::class]($container);
		$this->assertInstanceOf(expected: SettingsController::class, actual: $controller);
		$controller->updateAdminSettings();
	}//end testTheContainerFactoryPassesTheReporter()
}//end class
