<?php

/**
 * The offline edits admin setting (offline-edit-queue).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\AdminSettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Offline edits are off by default and an administrator can turn them on.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-administrators-control-offline-edits
 */
class OfflineEditsSettingTest extends TestCase {
	/**
	 * Build the real settings service over a mocked app config.
	 *
	 * @param IAppConfig $appConfig The app config
	 *
	 * @return AdminSettingsService
	 */
	private function service(IAppConfig $appConfig): AdminSettingsService {
		return new AdminSettingsService(
			appConfig: $appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The default is off.
	 *
	 * @return void
	 */
	public function testDefaultsToOff(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$appConfig->method('getValueBool')->willReturnArgument(2);

		$this->assertFalse($this->service($appConfig)->getAdminSettings()['offline_edits_enabled']);
	}//end testDefaultsToOff()

	/**
	 * An administrator turns it on.
	 *
	 * @return void
	 */
	public function testAnAdministratorTurnsItOn(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default) => $key === 'offline_edits_enabled' ? true : $default
		);
		$appConfig->expects($this->once())->method('setValueBool')->with('keepiq', 'offline_edits_enabled', true);

		$settings = $this->service($appConfig)->updateAdminSettings(['offline_edits_enabled' => true]);

		$this->assertTrue($settings['offline_edits_enabled']);
	}//end testAnAdministratorTurnsItOn()
}//end class
