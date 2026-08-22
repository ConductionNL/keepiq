<?php

/**
 * Unit tests for the MigrateAppConfigKeys repair step.
 *
 * Covers:
 *  - no stored doriath keys → info line, no writes;
 *  - a stored key is copied into the keepiq namespace;
 *  - Nextcloud-reserved keys (enabled / installed_version / types) are never
 *    copied — copying `enabled` with setValueString permanently breaks
 *    `occ app:enable` with an AppConfigTypeConflictException;
 *  - an empty old value is skipped;
 *  - an existing new value is never clobbered;
 *  - a write that throws is logged and the loop continues.
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

use OCA\Keepiq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateAppConfigKeys.
 */
class MigrateAppConfigKeysTest extends TestCase {
	/**
	 * An install with nothing stored under the old app id writes nothing.
	 *
	 * @return void
	 */
	public function testNoOpWhenNoOldKeys(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->with('doriath')->willReturn([]);
		$appConfig->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$this->step($appConfig)->run($output);
	}//end testNoOpWhenNoOldKeys()

	/**
	 * A stored value is copied into the new namespace.
	 *
	 * @return void
	 */
	public function testCopiesStoredValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['master_password_min_length']);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['doriath', 'master_password_min_length', '', false, '16'],
				['keepiq', 'master_password_min_length', '', false, ''],
			]
		);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('keepiq', 'master_password_min_length', '16');

		$this->step($appConfig)->run($this->createMock(IOutput::class));
	}//end testCopiesStoredValue()

	/**
	 * Nextcloud's own bookkeeping keys are never copied.
	 *
	 * @return void
	 */
	public function testSkipsReservedKeys(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['enabled', 'installed_version', 'types']);
		$appConfig->method('getValueString')->willReturn('yes');
		$appConfig->expects($this->never())->method('setValueString');

		$this->step($appConfig)->run($this->createMock(IOutput::class));
	}//end testSkipsReservedKeys()

	/**
	 * A key with no stored value under the old app id is skipped.
	 *
	 * @return void
	 */
	public function testSkipsEmptyOldValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['ca_status']);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->expects($this->never())->method('setValueString');

		$this->step($appConfig)->run($this->createMock(IOutput::class));
	}//end testSkipsEmptyOldValue()

	/**
	 * A value already present under the new app id is never overwritten, so a
	 * second run — or an admin edit made after the rename — is a no-op.
	 *
	 * @return void
	 */
	public function testNeverClobbersExistingNewValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['audit_retention_days']);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['doriath', 'audit_retention_days', '', false, '90'],
				['keepiq', 'audit_retention_days', '', false, '365'],
			]
		);
		$appConfig->expects($this->never())->method('setValueString');

		$this->step($appConfig)->run($this->createMock(IOutput::class));
	}//end testNeverClobbersExistingNewValue()

	/**
	 * A failing write is logged and does not abort the install.
	 *
	 * @return void
	 */
	public function testLogsAndContinuesOnWriteFailure(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['ca_status']);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['doriath', 'ca_status', '', false, 'healthy'],
				['keepiq', 'ca_status', '', false, ''],
			]
		);
		$appConfig->method('setValueString')->willThrowException(new RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		(new MigrateAppConfigKeys(appConfig: $appConfig, logger: $logger))->run($output);
	}//end testLogsAndContinuesOnWriteFailure()

	/**
	 * An unreadable old namespace degrades to a no-op rather than a fatal.
	 *
	 * @return void
	 */
	public function testUnreadableOldNamespaceIsANoOp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willThrowException(new RuntimeException('db down'));
		$appConfig->expects($this->never())->method('setValueString');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new MigrateAppConfigKeys(appConfig: $appConfig, logger: $logger))
			->run($this->createMock(IOutput::class));
	}//end testUnreadableOldNamespaceIsANoOp()

	/**
	 * The step reports a human-readable name.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		$this->assertStringContainsString(
			'doriath',
			$this->step($this->createMock(IAppConfig::class))->getName()
		);
	}//end testGetName()

	/**
	 * Build the step with a throwaway logger.
	 *
	 * @param IAppConfig $appConfig The mocked app config
	 *
	 * @return MigrateAppConfigKeys
	 */
	private function step(IAppConfig $appConfig): MigrateAppConfigKeys {
		return new MigrateAppConfigKeys(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()
}//end class
