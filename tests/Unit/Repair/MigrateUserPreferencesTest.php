<?php

/**
 * Unit tests for the MigrateUserPreferences repair step.
 *
 * Covers:
 *  - a stored preference is copied from the doriath app id to keepiq;
 *  - the opt-OUT case that motivates the whole step: `notify_shares = '0'`
 *    must survive, because every reader defaults it back to '1';
 *  - an open-valued key (`session_timeout`) is carried too — the reason this
 *    step enumerates users rather than enumerating a fixed value set;
 *  - a value already set under keepiq is never clobbered;
 *  - an empty old value is skipped;
 *  - a failing write is logged and the walk continues;
 *  - a failing user enumeration degrades to a warning, not a fatal.
 *
 * The later tests run the step against an in-memory user => app => key store so
 * they can assert the preferences that actually LAND — including on a second
 * run — rather than only the calls that were made. They pin:
 *  - the enumeration STRATEGY: users are walked with callForSeenUsers() and
 *    asked what they stored; the value-enumerating getUsersForUserValue() form
 *    is never used, because three of the eleven keys are open-valued;
 *  - all eleven preferences surviving verbatim, `'0'` and open values included;
 *  - the exact summary line and the exact nothing-to-do line;
 *  - a user whose key list is unreadable being skipped without stopping the
 *    walk, and the same for a preference that cannot be read;
 *  - that the old doriath rows are never deleted.
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

use Closure;
use OCA\Keepiq\Repair\MigrateUserPreferences;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateUserPreferences.
 */
class MigrateUserPreferencesTest extends TestCase {
	/**
	 * An explicit opt-out survives the rename.
	 *
	 * This is the case the step exists for: `notify_shares` reads back with a
	 * default of '1', so losing the stored '0' silently re-subscribes a user
	 * who turned the category off.
	 *
	 * @return void
	 */
	public function testCarriesAnExplicitOptOut(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->with('alice', 'doriath')->willReturn(['notify_shares']);
		$config->method('getUserValue')->willReturnMap(
			[
				['alice', 'doriath', 'notify_shares', '', '0'],
				['alice', 'keepiq', 'notify_shares', '', ''],
			]
		);
		$config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'keepiq', 'notify_shares', '0');

		$this->step($config, ['alice'])->run($this->createMock(IOutput::class));
	}//end testCarriesAnExplicitOptOut()

	/**
	 * An open-valued key is carried as well.
	 *
	 * `session_timeout` holds an arbitrary numeric string, which is exactly
	 * what a getUsersForUserValue()-based migration could not have enumerated.
	 *
	 * @return void
	 */
	public function testCarriesAnOpenValuedKey(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['session_timeout']);
		$config->method('getUserValue')->willReturnMap(
			[
				['bob', 'doriath', 'session_timeout', '', '60000'],
				['bob', 'keepiq', 'session_timeout', '', ''],
			]
		);
		$config->expects($this->once())
			->method('setUserValue')
			->with('bob', 'keepiq', 'session_timeout', '60000');

		$this->step($config, ['bob'])->run($this->createMock(IOutput::class));
	}//end testCarriesAnOpenValuedKey()

	/**
	 * A preference already set under the new app id is never overwritten.
	 *
	 * @return void
	 */
	public function testNeverClobbersExistingNewValue(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['default_view']);
		$config->method('getUserValue')->willReturnMap(
			[
				['alice', 'doriath', 'default_view', '', 'list'],
				['alice', 'keepiq', 'default_view', '', 'grid'],
			]
		);
		$config->expects($this->never())->method('setUserValue');

		$this->step($config, ['alice'])->run($this->createMock(IOutput::class));
	}//end testNeverClobbersExistingNewValue()

	/**
	 * A key with no stored value is skipped.
	 *
	 * @return void
	 */
	public function testSkipsEmptyOldValue(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['breach_check_opt_in']);
		$config->method('getUserValue')->willReturn('');
		$config->expects($this->never())->method('setUserValue');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$this->step($config, ['alice'])->run($output);
	}//end testSkipsEmptyOldValue()

	/**
	 * A user with nothing stored contributes nothing and is not an error.
	 *
	 * @return void
	 */
	public function testNoOpWhenNothingStored(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn([]);
		$config->expects($this->never())->method('setUserValue');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$this->step($config, ['alice', 'bob'])->run($output);
	}//end testNoOpWhenNothingStored()

	/**
	 * A failing write is logged and the walk continues.
	 *
	 * @return void
	 */
	public function testLogsAndContinuesOnWriteFailure(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['notify_security']);
		$config->method('getUserValue')->willReturnMap(
			[
				['alice', 'doriath', 'notify_security', '', '0'],
				['alice', 'keepiq', 'notify_security', '', ''],
				['bob', 'doriath', 'notify_security', '', '0'],
				['bob', 'keepiq', 'notify_security', '', ''],
			]
		);
		$config->method('setUserValue')->willThrowException(new RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$this->step($config, ['alice', 'bob'], $logger)->run($this->createMock(IOutput::class));
	}//end testLogsAndContinuesOnWriteFailure()

	/**
	 * A failing user enumeration warns rather than aborting the install.
	 *
	 * @return void
	 */
	public function testUserEnumerationFailureIsNotFatal(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setUserValue');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willThrowException(new RuntimeException('ldap down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new MigrateUserPreferences(config: $config, userManager: $userManager, logger: $logger))
			->run($output);
	}//end testUserEnumerationFailureIsNotFatal()

	/**
	 * The step reports a human-readable name.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		$this->assertStringContainsString(
			'doriath',
			$this->step($this->createMock(IConfig::class), [])->getName()
		);
	}//end testGetName()

	/**
	 * The step walks USERS, never a value set.
	 *
	 * `session_timeout`, `expiry_max_age_days` and `default_secret_type` hold
	 * open values, so the getUsersForUserValue(app, key, value) form used by the
	 * planninq pilot cannot be exhaustive here. This test fails the moment the
	 * step regresses to it — or to the full-backend callForAllUsers() walk.
	 *
	 * @return void
	 */
	public function testEnumeratesUsersRatherThanValues(): void {
		$store = ['alice' => ['doriath' => ['session_timeout' => '300']]];
		$writes = 0;

		$config = $this->inMemoryConfig($store, $writes);
		$config->expects($this->never())->method('getUsersForUserValue');
		$config->expects($this->never())->method('getUserValueForUsers');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('callForAllUsers');
		$userManager->expects($this->once())->method('callForSeenUsers')->willReturnCallback(
			function (Closure $callback): void {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('alice');
				$callback($user);
			}
		);

		(new MigrateUserPreferences(
			config: $config,
			userManager: $userManager,
			logger: $this->createMock(LoggerInterface::class),
		))->run($this->createMock(IOutput::class));

		$this->assertSame('300', $store['alice']['keepiq']['session_timeout']);
	}//end testEnumeratesUsersRatherThanValues()

	/**
	 * Every one of the eleven preferences survives the rename verbatim.
	 *
	 * Two of these are the reason the step exists: `notify_shares = '0'` reads
	 * back with a default of `'1'`, so losing it re-subscribes a user who opted
	 * out; `offline_cache_optin = '0'` likewise defaults back ON, restoring the
	 * on-device vault cache someone turned off. `session_timeout` reverting
	 * would LENGTHEN the unlocked-vault window. None of these would error.
	 *
	 * @return void
	 */
	public function testCarriesEveryStoredPreferenceVerbatim(): void {
		$prefs = [
			'session_timeout' => '300',
			'notify_shares' => '0',
			'notify_requests' => '0',
			'notify_group_shares' => '0',
			'notify_security' => '0',
			'default_secret_type' => 'ssh_key',
			'default_view' => 'grid',
			'health_staleness_days' => '90',
			'breach_check_opt_in' => '1',
			'expiry_max_age_days' => '30',
			'offline_cache_optin' => '0',
		];
		$store = ['alice' => ['doriath' => $prefs]];
		$writes = 0;

		$this->step($this->inMemoryConfig($store, $writes), ['alice'])
			->run($this->createMock(IOutput::class));

		$this->assertSame($prefs, $store['alice']['keepiq']);
		$this->assertSame($prefs, $store['alice']['doriath']);
		$this->assertSame(11, $writes);
	}//end testCarriesEveryStoredPreferenceVerbatim()

	/**
	 * The summary line reports the copied and the left-alone counts exactly.
	 *
	 * @return void
	 */
	public function testSummaryReportsMigratedAndAlreadyPresentCounts(): void {
		$store = [
			'alice' => [
				'doriath' => ['notify_shares' => '0', 'session_timeout' => '300'],
				'keepiq' => ['session_timeout' => '900'],
			],
			'bob' => ['doriath' => ['offline_cache_optin' => '0']],
		];
		$writes = 0;

		$lines = [];
		$this->step($this->inMemoryConfig($store, $writes), ['alice', 'bob'])
			->run($this->recordingOutput($lines));

		$this->assertSame(
			['MigrateUserPreferences: migrated 2 preference(s); 1 already set under keepiq.'],
			$lines
		);
		$this->assertSame('0', $store['alice']['keepiq']['notify_shares']);
		$this->assertSame('900', $store['alice']['keepiq']['session_timeout']);
		$this->assertSame('0', $store['bob']['keepiq']['offline_cache_optin']);
	}//end testSummaryReportsMigratedAndAlreadyPresentCounts()

	/**
	 * An install with nothing stored says so, in as many words.
	 *
	 * @return void
	 */
	public function testNothingStoredEmitsTheNothingToDoLine(): void {
		$store = [];
		$writes = 0;

		$lines = [];
		$this->step($this->inMemoryConfig($store, $writes), ['alice', 'bob'])
			->run($this->recordingOutput($lines));

		$this->assertSame(
			['MigrateUserPreferences: no stored doriath user preferences on this install; nothing to do.'],
			$lines
		);
		$this->assertSame(0, $writes);
	}//end testNothingStoredEmitsTheNothingToDoLine()

	/**
	 * Every seen user is visited: the callback never stops the walk.
	 *
	 * IUserManager reads a `false` return as "stop iterating", so the callback
	 * must return null. If it ever returned false, only the first user would be
	 * migrated and the rest would silently keep the shipped defaults.
	 *
	 * @return void
	 */
	public function testEveryUserIsVisitedEvenWhenOneHasNothingStored(): void {
		$store = [
			'alice' => ['doriath' => ['notify_shares' => '0']],
			'carol' => ['doriath' => ['offline_cache_optin' => '0']],
		];
		$writes = 0;

		$this->step($this->inMemoryConfig($store, $writes), ['alice', 'bob', 'carol'])
			->run($this->createMock(IOutput::class));

		$this->assertSame('0', $store['alice']['keepiq']['notify_shares']);
		$this->assertSame('0', $store['carol']['keepiq']['offline_cache_optin']);
		$this->assertArrayNotHasKey('bob', $store);
	}//end testEveryUserIsVisitedEvenWhenOneHasNothingStored()

	/**
	 * A second run copies nothing and never deletes the old rows.
	 *
	 * The step is registered under both `<install>` and `<post-migration>`, so
	 * re-running it on a live instance must be inert; and because the doriath
	 * rows stay put, a rollback to the previous app id still finds them.
	 *
	 * @return void
	 */
	public function testSecondRunIsANoOp(): void {
		$prefs = ['notify_security' => '0', 'session_timeout' => '300'];
		$store = ['alice' => ['doriath' => $prefs]];
		$writes = 0;

		$config = $this->inMemoryConfig($store, $writes);
		$config->expects($this->never())->method('deleteUserValue');

		$lines = [];
		$output = $this->recordingOutput($lines);
		$step = $this->step($config, ['alice']);
		$step->run($output);
		$step->run($output);

		$this->assertSame(2, $writes);
		$this->assertSame($prefs, $store['alice']['keepiq']);
		$this->assertSame($prefs, $store['alice']['doriath']);
		$this->assertSame('MigrateUserPreferences: migrated 2 preference(s); 0 already set under keepiq.', $lines[0]);
		$this->assertSame('MigrateUserPreferences: migrated 0 preference(s); 2 already set under keepiq.', $lines[1]);
	}//end testSecondRunIsANoOp()

	/**
	 * A user whose key list cannot be read is skipped; the walk continues.
	 *
	 * @return void
	 */
	public function testUnreadableKeyListSkipsThatUserAndContinues(): void {
		$written = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturnCallback(
			static function (string $userId): array {
				if ($userId === 'alice') {
					throw new RuntimeException('preferences table locked');
				}

				return ['notify_security'];
			}
		);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $userId, string $appName) {
				if ($appName === 'doriath') {
					return '0';
				}

				return '';
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $userId, string $appName, string $key, $value) use (&$written): void {
				$written[$userId] = $key . '=' . $value;
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
		$this->step($config, ['alice', 'bob'], $logger)->run($this->recordingOutput($lines));

		$this->assertSame(['bob' => 'notify_security=0'], $written);
		$this->assertSame('preferences table locked', $context['exception']);
		$this->assertSame(
			['MigrateUserPreferences: migrated 1 preference(s); 0 already set under keepiq.'],
			$lines
		);
	}//end testUnreadableKeyListSkipsThatUserAndContinues()

	/**
	 * A preference that cannot be read is logged with its key; the next lands.
	 *
	 * @return void
	 */
	public function testUnreadablePreferenceIsLoggedAndTheNextKeyStillLands(): void {
		$written = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['notify_shares', 'session_timeout']);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $userId, string $appName, string $key) {
				if ($key === 'notify_shares') {
					throw new RuntimeException('unreadable row');
				}

				if ($appName === 'doriath') {
					return '300';
				}

				return '';
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $userId, string $appName, string $key, $value) use (&$written): void {
				$written[$key] = $value;
			}
		);

		$context = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->willReturnCallback(
			static function ($message, array $loggedContext) use (&$context): void {
				$context = $loggedContext;
			}
		);

		$this->step($config, ['alice'], $logger)->run($this->createMock(IOutput::class));

		$this->assertSame(['session_timeout' => '300'], $written);
		$this->assertSame('notify_shares', $context['key']);
		$this->assertSame('unreadable row', $context['exception']);
	}//end testUnreadablePreferenceIsLoggedAndTheNextKeyStillLands()

	/**
	 * A failed enumeration leaves the preferences alone and says where they are.
	 *
	 * @return void
	 */
	public function testEnumerationFailureReportsWhereThePreferencesStayed(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setUserValue');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willThrowException(new RuntimeException('ldap down'));

		$context = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->willReturnCallback(
			static function ($message, array $loggedContext) use (&$context): void {
				$context = $loggedContext;
			}
		);

		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$output->method('warning')->willReturnCallback(
			static function ($message) use (&$warnings): void {
				$warnings[] = (string)$message;
			}
		);

		(new MigrateUserPreferences(config: $config, userManager: $userManager, logger: $logger))
			->run($output);

		$this->assertSame(
			['MigrateUserPreferences: user enumeration failed; preferences left under the doriath app id.'],
			$warnings
		);
		$this->assertSame('ldap down', $context['exception']);
	}//end testEnumerationFailureReportsWhereThePreferencesStayed()

	/**
	 * An IConfig backed by an in-memory user => app => key => value store.
	 *
	 * Lets a test assert the preferences that actually land, and makes a second
	 * run observable, which a per-call mock cannot do.
	 *
	 * @param array<string, array<string, array<string, string>>> $store The store, by reference
	 * @param int $writes Incremented on every setUserValue(), by reference
	 *
	 * @return IConfig
	 */
	private function inMemoryConfig(array &$store, int &$writes): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturnCallback(
			static function (string $userId, string $appName) use (&$store): array {
				return array_keys(($store[$userId][$appName] ?? []));
			}
		);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $userId, string $appName, string $key, $default = '') use (&$store) {
				return ($store[$userId][$appName][$key] ?? $default);
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $userId, string $appName, string $key, $value) use (&$store, &$writes): void {
				$store[$userId][$appName][$key] = (string)$value;
				$writes++;
			}
		);

		return $config;
	}//end inMemoryConfig()

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
	 * An IUserManager whose callForSeenUsers() yields the given UIDs.
	 *
	 * The walk honours the IUserManager contract: a callback returning `false`
	 * stops the iteration, so a test can tell whether the step keeps going.
	 *
	 * @param string[] $userIds The UIDs to yield, in order
	 *
	 * @return IUserManager
	 */
	private function seenUsers(array $userIds): IUserManager {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (Closure $callback) use ($userIds): void {
				foreach ($userIds as $userId) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($userId);
					if ($callback($user) === false) {
						return;
					}
				}
			}
		);

		return $userManager;
	}//end seenUsers()

	/**
	 * Build the step over a fixed set of seen users.
	 *
	 * @param IConfig $config The mocked user-value store
	 * @param string[] $userIds The UIDs callForSeenUsers() should yield
	 * @param LoggerInterface|null $logger An optional logger expectation
	 *
	 * @return MigrateUserPreferences
	 */
	private function step(
		IConfig $config,
		array $userIds,
		?LoggerInterface $logger = null,
	): MigrateUserPreferences {
		return new MigrateUserPreferences(
			config: $config,
			userManager: $this->seenUsers($userIds),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end step()
}//end class
