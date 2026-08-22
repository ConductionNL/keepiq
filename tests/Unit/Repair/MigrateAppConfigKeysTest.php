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
 * The later tests run the step against an in-memory two-namespace store so they
 * can assert the values that actually LAND — including on a second run — rather
 * than only the calls that were made. They pin:
 *  - the exact summary line, per outcome bucket;
 *  - that the reserved-key skip is an exact match, not a prefix match;
 *  - that a falsy-but-meaningful `'0'` survives;
 *  - that keys this release does not know about are carried too, which is the
 *    whole point of enumerating getKeys() instead of a hardcoded list;
 *  - that the old doriath rows are never deleted, so a rollback still works;
 *  - that a failing write does not stop the keys after it.
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
	 * The keepiq side deliberately reads back EMPTY: if it returned a value too,
	 * the never-overwrite guard would suppress the write and this test would
	 * pass even with RESERVED_KEYS emptied.
	 *
	 * @return void
	 */
	public function testSkipsReservedKeys(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['enabled', 'installed_version', 'types']);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app): string {
				if ($app === 'doriath') {
					return 'yes';
				}

				return '';
			}
		);
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
	 * The summary line counts each outcome in its own bucket.
	 *
	 * The counts are the only signal an admin gets from this step, so they are
	 * pinned exactly: one key copied, one left alone because keepiq already had
	 * a value, one with nothing to copy, one Nextcloud-reserved key skipped.
	 *
	 * @return void
	 */
	public function testSummaryCountsEveryOutcome(): void {
		$store = [
			'doriath' => [
				'enabled' => 'yes',
				'master_password_min_length' => '16',
				'audit_retention_days' => '90',
				'ca_status' => '',
			],
			'keepiq' => ['audit_retention_days' => '365'],
		];
		$writes = 0;

		$lines = [];
		$this->step($this->inMemoryAppConfig($store, $writes))->run($this->recordingOutput($lines));

		$this->assertSame(
			[
				'MigrateAppConfigKeys: 1 key(s) migrated, 1 already present, '
				. '1 had no value to migrate, 1 skipped as Nextcloud-reserved.',
			],
			$lines
		);
		$this->assertSame(
			['audit_retention_days' => '365', 'master_password_min_length' => '16'],
			$store['keepiq']
		);
		$this->assertSame(1, $writes);
	}//end testSummaryCountsEveryOutcome()

	/**
	 * The reserved-key skip matches the whole key, not a prefix.
	 *
	 * `enabled`, `installed_version` and `types` are Nextcloud's; a key that
	 * merely starts with one of those names is this app's own and must travel.
	 *
	 * @return void
	 */
	public function testReservedSkipIsAnExactKeyMatch(): void {
		$store = [
			'doriath' => [
				'enabled' => 'yes',
				'enabled_suites' => 'rsa-4096',
				'installed_version' => '1.2.3',
				'installed_version_previous' => '1.2.2',
				'types' => 'dav',
				'types_seen' => '4',
			],
			'keepiq' => [],
		];
		$writes = 0;

		$this->step($this->inMemoryAppConfig($store, $writes))->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'enabled_suites' => 'rsa-4096',
				'installed_version_previous' => '1.2.2',
				'types_seen' => '4',
			],
			$store['keepiq']
		);
	}//end testReservedSkipIsAnExactKeyMatch()

	/**
	 * A stored `'0'` is a real value and must be copied.
	 *
	 * `block_on_hibp_hit = '0'` is an admin who deliberately turned the breach
	 * block OFF. A truthiness check in place of the `=== ''` test would drop it
	 * and silently restore the shipped default.
	 *
	 * @return void
	 */
	public function testCopiesAFalsyButMeaningfulValue(): void {
		$store = ['doriath' => ['block_on_hibp_hit' => '0'], 'keepiq' => []];
		$writes = 0;

		$this->step($this->inMemoryAppConfig($store, $writes))->run($this->createMock(IOutput::class));

		$this->assertSame('0', $store['keepiq']['block_on_hibp_hit']);
		$this->assertSame(1, $writes);
	}//end testCopiesAFalsyButMeaningfulValue()

	/**
	 * Keys this release knows nothing about are carried as well.
	 *
	 * The step enumerates `getKeys()` precisely so that cached metrics and keys
	 * written by past releases travel too. A hardcoded key list would drop them.
	 *
	 * @return void
	 */
	public function testCopiesKeysThisReleaseDoesNotKnowAbout(): void {
		$stored = [
			'compliance_metrics_2026_q1' => '{"secrets":12}',
			'a_key_from_a_past_release' => 'kept',
		];
		$store = ['doriath' => $stored, 'keepiq' => []];
		$writes = 0;

		$this->step($this->inMemoryAppConfig($store, $writes))->run($this->createMock(IOutput::class));

		$this->assertSame($stored, $store['keepiq']);
	}//end testCopiesKeysThisReleaseDoesNotKnowAbout()

	/**
	 * The whole policy floor round-trips verbatim and the old rows stay put.
	 *
	 * Values are copied as raw strings, so a JSON payload must arrive byte for
	 * byte; and because the doriath rows are never deleted, a rollback to the
	 * previous app id still finds its configuration intact.
	 *
	 * @return void
	 */
	public function testCopiesThePolicyFloorVerbatimAndNeverDeletesTheOldRows(): void {
		$policy = [
			'master_password_min_length' => '16',
			'master_password_min_score' => '3',
			'min_zxcvbn_score' => '4',
			'block_on_hibp_hit' => '1',
			'policy_exempt_types' => '["ssh_key","api_key"]',
			'ca_status' => 'healthy',
			'audit_retention_days' => '365',
		];
		$store = ['doriath' => $policy, 'keepiq' => []];
		$writes = 0;

		$appConfig = $this->inMemoryAppConfig($store, $writes);
		$appConfig->expects($this->never())->method('deleteKey');
		$appConfig->expects($this->never())->method('deleteApp');

		$this->step($appConfig)->run($this->createMock(IOutput::class));

		$this->assertSame($policy, $store['keepiq']);
		$this->assertSame($policy, $store['doriath']);
	}//end testCopiesThePolicyFloorVerbatimAndNeverDeletesTheOldRows()

	/**
	 * A second run copies nothing and reports everything as already present.
	 *
	 * This is the property that makes the step safe to re-run on a live
	 * instance: it is registered under both `<install>` and `<post-migration>`.
	 *
	 * @return void
	 */
	public function testSecondRunIsANoOp(): void {
		$store = [
			'doriath' => ['min_zxcvbn_score' => '4', 'audit_retention_days' => '90'],
			'keepiq' => [],
		];
		$writes = 0;

		$lines = [];
		$output = $this->recordingOutput($lines);
		$step = $this->step($this->inMemoryAppConfig($store, $writes));
		$step->run($output);
		$step->run($output);

		$this->assertSame(2, $writes);
		$this->assertSame(
			['min_zxcvbn_score' => '4', 'audit_retention_days' => '90'],
			$store['keepiq']
		);
		$this->assertStringContainsString('2 key(s) migrated, 0 already present', $lines[0]);
		$this->assertStringContainsString('0 key(s) migrated, 2 already present', $lines[1]);
	}//end testSecondRunIsANoOp()

	/**
	 * A key whose write throws is logged with its name; the next key still lands.
	 *
	 * A repair step that aborts here would abort the install, and the keys after
	 * the failing one would never be migrated at all.
	 *
	 * @return void
	 */
	public function testContinuesToTheNextKeyAfterAWriteFailure(): void {
		$written = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['ca_status', 'audit_retention_days']);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key): string {
				if ($app !== 'doriath') {
					return '';
				}

				if ($key === 'ca_status') {
					return 'healthy';
				}

				return '90';
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				if ($key === 'ca_status') {
					throw new RuntimeException('boom');
				}

				$written[$key] = $value;
				return true;
			}
		);

		$context = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->willReturnCallback(
			static function ($message, array $loggedContext) use (&$context): void {
				$context = $loggedContext;
			}
		);

		$lines = [];
		(new MigrateAppConfigKeys(appConfig: $appConfig, logger: $logger))
			->run($this->recordingOutput($lines));

		$this->assertSame(['audit_retention_days' => '90'], $written);
		$this->assertSame('ca_status', $context['key']);
		$this->assertSame('boom', $context['exception']);
		$this->assertStringContainsString('1 key(s) migrated', $lines[0]);
	}//end testContinuesToTheNextKeyAfterAWriteFailure()

	/**
	 * An unreadable old namespace still emits the nothing-to-do line.
	 *
	 * The failure is logged with the underlying reason and the step returns
	 * normally, because a repair step that throws aborts the install.
	 *
	 * @return void
	 */
	public function testUnreadableOldNamespaceReportsNothingToDo(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willThrowException(new RuntimeException('db down'));
		$appConfig->expects($this->never())->method('setValueString');

		$context = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->willReturnCallback(
			static function ($message, array $loggedContext) use (&$context): void {
				$context = $loggedContext;
			}
		);

		$lines = [];
		(new MigrateAppConfigKeys(appConfig: $appConfig, logger: $logger))
			->run($this->recordingOutput($lines));

		$this->assertSame(
			['MigrateAppConfigKeys: no stored doriath configuration on this install; nothing to do.'],
			$lines
		);
		$this->assertSame('db down', $context['exception']);
	}//end testUnreadableOldNamespaceReportsNothingToDo()

	/**
	 * An IAppConfig backed by an in-memory namespace => key => value store.
	 *
	 * Lets a test assert the values that actually land, and makes a second run
	 * observable, which a per-call mock cannot do.
	 *
	 * @param array<string, array<string, string>> $store The backing store, by reference
	 * @param int $writes Incremented on every setValueString(), by reference
	 *
	 * @return IAppConfig
	 */
	private function inMemoryAppConfig(array &$store, int &$writes): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturnCallback(
			static function (string $app) use (&$store): array {
				return array_keys(($store[$app] ?? []));
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$app][$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store, &$writes): bool {
				$store[$app][$key] = $value;
				$writes++;
				return true;
			}
		);

		return $appConfig;
	}//end inMemoryAppConfig()

	/**
	 * An IOutput that records every info() line so it can be asserted on.
	 *
	 * @param array<int, string> $lines The collected lines, by reference
	 *
	 * @return IOutput
	 */
	private function recordingOutput(array &$lines): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function ($message) use (&$lines): void {
				$lines[] = (string)$message;
			}
		);

		return $output;
	}//end recordingOutput()

	/**
	 * Build the step with a throwaway logger.
	 *
	 * @param IAppConfig $appConfig The mocked app config
	 *
	 * @return MigrateAppConfigKeys
	 */
	/**
	 * An unreadable SOURCE value is logged, and the next key still migrates.
	 *
	 * The two reads used to sit outside the try, so a throwing read escaped
	 * run(). This step also runs under <install>, so an app that cannot
	 * finish its repair steps does not enable at all — every route goes with
	 * it. One unreadable key must cost that key its value, not the install.
	 *
	 * @return void
	 *
	 * @spec exclude Covers the same one-off doriath->keepiq rename plumbing
	 *       the class itself excludes: the step moves IAppConfig rows between
	 *       namespaces and adds no behaviour of its own, so there is no
	 *       capability spec to point at. What it pins is the step's own
	 *       safety contract - that it survives an unreadable key rather than
	 *       aborting the install.
	 */
	public function testAThrowingReadIsLoggedAndTheNextKeyStillMigrates(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['boom', 'master_password_min_length']);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false): string {
				if ($key === 'boom') {
					throw new \RuntimeException('config store unavailable');
				}

				return ($app === 'doriath' && $key === 'master_password_min_length') ? '16' : '';
			}
		);

		// The key AFTER the unreadable one must still land.
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('keepiq', 'master_password_min_length', '16');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$step = new MigrateAppConfigKeys(appConfig: $appConfig, logger: $logger);

		// The assertion that matters: run() RETURNS rather than throwing.
		$step->run($this->createMock(IOutput::class));
	}//end testAThrowingReadIsLoggedAndTheNextKeyStillMigrates()

	private function step(IAppConfig $appConfig): MigrateAppConfigKeys {
		return new MigrateAppConfigKeys(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()
}//end class
