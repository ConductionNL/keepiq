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
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (Closure $callback) use ($userIds): void {
				foreach ($userIds as $userId) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($userId);
					$callback($user);
				}
			}
		);

		return new MigrateUserPreferences(
			config: $config,
			userManager: $userManager,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end step()
}//end class
