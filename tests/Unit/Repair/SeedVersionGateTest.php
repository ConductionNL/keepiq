<?php

/**
 * Unit tests for SeedVersionGate.
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

use OCA\Keepiq\Repair\SeedVersionGate;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SeedVersionGate.
 *
 * @covers \OCA\Keepiq\Repair\SeedVersionGate
 */
class SeedVersionGateTest extends TestCase {

	/**
	 * A gate over a debug flag and the stored app config values.
	 *
	 * @param bool                 $debug  The debug system value
	 * @param array<string,string> $values App config key to value
	 *
	 * @return SeedVersionGate
	 */
	private function gate(bool $debug, array $values): SeedVersionGate {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(
			static fn (string $key, bool $default) => $key === 'debug' ? $debug : $default
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default) => $app === 'keepiq' ? ($values[$key] ?? $default) : 'other-app'
		);

		return new SeedVersionGate(config: $config, appConfig: $appConfig);
	}//end gate()

	/**
	 * Outside debug mode no seed runs.
	 *
	 * @return void
	 */
	public function testNotDueOutsideDebug(): void {
		$this->assertNull($this->gate(false, ['installed_version' => '1.0.0'])->dueVersion('seed_key'));
	}//end testNotDueOutsideDebug()

	/**
	 * A seed that already ran for the installed version does not run again.
	 *
	 * @return void
	 */
	public function testNotDueWhenSeededForThisVersion(): void {
		$gate = $this->gate(true, ['installed_version' => '1.0.0', 'seed_key' => '1.0.0']);
		$this->assertNull($gate->dueVersion('seed_key'));
	}//end testNotDueWhenSeededForThisVersion()

	/**
	 * A seed recorded for another version, or under another key, runs.
	 *
	 * @return void
	 */
	public function testDueForANewVersion(): void {
		$gate = $this->gate(true, ['installed_version' => '1.1.0', 'seed_key' => '1.0.0', 'other_key' => '1.1.0']);
		$this->assertSame('1.1.0', $gate->dueVersion('seed_key'));
	}//end testDueForANewVersion()

	/**
	 * Without an installed version the seed always runs, even when the key
	 * holds an empty version.
	 *
	 * @return void
	 */
	public function testDueWithoutAnInstalledVersion(): void {
		$this->assertSame('', $this->gate(true, [])->dueVersion('seed_key'));
		$this->assertSame('', $this->gate(true, ['seed_key' => ''])->dueVersion('seed_key'));
	}//end testDueWithoutAnInstalledVersion()

	/**
	 * Marking a seed writes its version under its key in the app's config.
	 *
	 * @return void
	 */
	public function testMarkSeededWritesTheVersion(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('setValueString')->with('keepiq', 'seed_key', '1.1.0');

		(new SeedVersionGate(config: $this->createMock(IConfig::class), appConfig: $appConfig))->markSeeded('seed_key', '1.1.0');
	}//end testMarkSeededWritesTheVersion()
}//end class
