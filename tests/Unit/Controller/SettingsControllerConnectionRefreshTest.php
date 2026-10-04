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
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\DomainOverrideRegistrar;
use OCA\Keepiq\Controller\AdminAreaSettingsController;
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
 * @covers \OCA\Keepiq\AppInfo\SettingsControllerFactory
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
	 * @return AdminAreaSettingsController
	 */
	private function controller(?ConnectionReporter $reporter): AdminAreaSettingsController {
		return new AdminAreaSettingsController(
			request: $this->request,
			settingsService: $this->settingsService,
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
		$this->settingsService->method('updateAreaSettings')->willReturn(['breach_check_enabled' => false]);
		$this->reporter->expects($this->once())->method('breachCheckSaved')->willReturn(true);

		$response = $this->controller(reporter: $this->reporter)->updateGeneralSettings();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['breach_check_enabled' => false], actual: $response->getData());
	}//end testSavingTheSwitchAsksForARefresh()

	/**
	 * A save that leaves the switch alone asks for nothing.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheSwitchAsksForNothing(): void {
		$this->request->method('getParams')->willReturn(['offline_cache_enabled' => true]);
		$this->settingsService->method('updateAreaSettings')->willReturn([]);
		$this->reporter->expects($this->never())->method('breachCheckSaved');

		$this->controller(reporter: $this->reporter)->updateGeneralSettings();
	}//end testASaveWithoutTheSwitchAsksForNothing()

	/**
	 * A refused save wrote nothing, so it asks for nothing.
	 *
	 * @return void
	 */
	public function testARefusedSaveAsksForNothing(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => true]);
		$this->settingsService->method('updateAreaSettings')->willThrowException(new InvalidArgumentException('bad value'));
		$this->reporter->expects($this->never())->method('breachCheckSaved');

		$response = $this->controller(reporter: $this->reporter)->updateGeneralSettings();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testARefusedSaveAsksForNothing()

	/**
	 * Without the reporter the save answers exactly as before.
	 *
	 * @return void
	 */
	public function testWithoutTheReporterTheSaveStillAnswers(): void {
		$this->request->method('getParams')->willReturn(['breach_check_enabled' => true]);
		$this->settingsService->method('updateAreaSettings')->willReturn(['breach_check_enabled' => true]);

		$response = $this->controller(reporter: null)->updateGeneralSettings();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testWithoutTheReporterTheSaveStillAnswers()

	/**
	 * The hand-built container factory passes the admin area check to the
	 * settings controller, so `/api/settings` reports the areas the user
	 * holds (admin-scoped-roles §2.5). The controller's own default is null,
	 * so a factory that forgets the argument still builds and reports none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.5
	 */
	public function testTheContainerFactoryPassesTheAreaCheck(): void {
		$factories = [];
		$context   = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new DomainOverrideRegistrar())->register(context: $context);
		$this->assertArrayHasKey(key: SettingsController::class, array: $factories);

		$user = $this->createMock(originalClassName: \OCP\IUser::class);
		$user->method('getUID')->willReturn('helpdesk');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$areas = $this->createMock(originalClassName: \OCA\Keepiq\Service\AdminAreaAuthorizer::class);
		$areas->method('areasOf')->with('helpdesk')->willReturn(['people']);
		$services = [
			IRequest::class           => $this->request,
			SettingsService::class    => $this->settingsService,
			IUserSession::class       => $session,
			\OCA\Keepiq\Service\AdminAreaAuthorizer::class => $areas,
			\OCA\Keepiq\Service\TwoFactorGate::class => $this->createMock(originalClassName: \OCA\Keepiq\Service\TwoFactorGate::class),
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $services[$id]
		);

		$this->settingsService->method('getSettings')->willReturn(['isAdmin' => false]);

		$controller = $factories[SettingsController::class]($container);
		$this->assertSame(['isAdmin' => false, 'adminAreas' => ['people']], $controller->index()->getData());
	}//end testTheContainerFactoryPassesTheAreaCheck()
}//end class
