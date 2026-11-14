<?php

/**
 * Unit tests for InitializeSettings.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Repair\InitializeSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Tests for InitializeSettings.
 *
 * @covers \OCA\Keepiq\Repair\InitializeSettings
 */
class InitializeSettingsTest extends TestCase {

	/**
	 * Run the step over the stored values and return what it wrote.
	 *
	 * @param array<string,string> $stored App config key to value already present
	 * @param array<int,string>    $infos  Collects the info lines
	 *
	 * @return array<string,string> The values written, by key
	 */
	private function runStep(array $stored, array &$infos = []): array {
		$written = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($stored): string {
				TestCase::assertSame('keepiq', $app);
				return $stored[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				TestCase::assertSame('keepiq', $app);
				$written[$key] = $value;
				return true;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('keepiq')->willReturn('2.4.1');
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $m) use (&$infos): void {
			$infos[] = $m;
		});

		(new InitializeSettings($config, $apps))->run($output);

		return $written;
	}//end runStep()

	/**
	 * The repair step has a stable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new InitializeSettings($this->createMock(IAppConfig::class), $this->createMock(IAppManager::class));

		$this->assertSame('Initialize Keepiq default configuration', $step->getName());
	}//end testName()

	/**
	 * A fresh install gets every default and the current config version.
	 *
	 * @return void
	 */
	public function testAFreshInstallGetsEveryDefaultAndTheVersion(): void {
		$infos = [];

		$written = $this->runStep([], $infos);

		$this->assertSame(
			[
				'master_password_min_length' => '12',
				'master_password_min_score' => '3',
				'session_timeout_default' => '600000',
				'default_notify_shares' => '1',
				'default_notify_group_shares' => '1',
				'default_notify_security' => '1',
				'default_notify_requests' => '1',
				'config_version' => '2.4.1',
			],
			$written
		);
		$this->assertSame('Initializing Keepiq configuration...', $infos[0]);
		$this->assertContains('Set default config: master_password_min_length = 12', $infos);
		$this->assertSame('Keepiq configuration is current (version: 2.4.1)', end($infos));
	}//end testAFreshInstallGetsEveryDefaultAndTheVersion()

	/**
	 * Values an admin already set are never overwritten; the version is always refreshed.
	 *
	 * @return void
	 */
	public function testExistingValuesAreKept(): void {
		$infos = [];

		$written = $this->runStep(
			[
				'master_password_min_length' => '16',
				'master_password_min_score' => '4',
				'session_timeout_default' => '300000',
				'default_notify_shares' => '0',
				'default_notify_group_shares' => '0',
				'default_notify_security' => '0',
				'config_version' => '2.0.0',
			],
			$infos
		);

		$this->assertSame(['default_notify_requests' => '1', 'config_version' => '2.4.1'], $written);
		$this->assertNotContains('Set default config: master_password_min_length = 12', $infos);
	}//end testExistingValuesAreKept()
}//end class
