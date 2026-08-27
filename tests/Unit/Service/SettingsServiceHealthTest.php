<?php

/**
 * Unit tests for the password-health settings on SettingsService.
 *
 * Covers the new breach_check_enabled admin gate (default off + persist) and
 * the health_staleness_days user-preference validation.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the password-health-related settings.
 */
class SettingsServiceHealthTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up the service with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);

		$this->service = new SettingsService(
			$this->appConfig,
			$this->config,
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The breach-check admin gate defaults to off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
	 */
	public function testBreachCheckDefaultsOff(): void {
		// The default passed to getValueBool for breach_check_enabled is false.
		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => $default
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $default
		);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $default
		);

		$settings = $this->service->getAdminSettings();

		$this->assertArrayHasKey('breach_check_enabled', $settings);
		$this->assertFalse($settings['breach_check_enabled']);
	}//end testBreachCheckDefaultsOff()

	/**
	 * Enabling the breach-check gate persists a boolean true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
	 */
	public function testBreachCheckPersistsEnabled(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->appConfig->method('getValueString')->willReturnArgument(2);

		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with($this->anything(), 'breach_check_enabled', true);

		$this->service->updateAdminSettings(['breach_check_enabled' => true]);
	}//end testBreachCheckPersistsEnabled()

	/**
	 * A valid staleness threshold is accepted and persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
	 */
	public function testValidStalenessThresholdAccepted(): void {
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', $this->anything(), 'health_staleness_days', '180');
		$this->config->method('getUserValue')->willReturnArgument(3);
		$this->appConfig->method('getValueString')->willReturnArgument(2);

		$this->service->updateUserPreferences('alice', ['health_staleness_days' => '180']);
	}//end testValidStalenessThresholdAccepted()

	/**
	 * An out-of-set staleness threshold is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
	 */
	public function testInvalidStalenessThresholdRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->updateUserPreferences('alice', ['health_staleness_days' => '7']);
	}//end testInvalidStalenessThresholdRejected()
}//end class
