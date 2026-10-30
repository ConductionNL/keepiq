<?php

/**
 * Unit tests for the SeedDemoVaults repair step.
 *
 * Covers:
 *  - no owners or no password configured → nothing minted;
 *  - an administrator, an unknown account, or an account that already has a
 *    suite of any status → never touched;
 *  - a named account without a suite → one suite with the configured
 *    password, and the demo folders and secrets in it;
 *  - the shipped demo vault and example-set fragment name only demo data.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/demo-vault/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Repair\SeedDemoVaults;
use OCA\Keepiq\Repair\SeedDevelopmentData;
use OCA\Keepiq\Repair\SeedDevelopmentSecrets;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedDemoVaults.
 */
class SeedDemoVaultsTest extends TestCase {

	private const DEMO_VAULT = __DIR__ . '/../../../lib/Settings/demo-vault.json';

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/** @var SeedDevelopmentData&MockObject */
	private SeedDevelopmentData $suites;

	/** @var SeedDevelopmentSecrets&MockObject */
	private SeedDevelopmentSecrets $secrets;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suiteMapper;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->suites      = $this->createMock(originalClassName: SeedDevelopmentData::class);
		$this->secrets     = $this->createMock(originalClassName: SeedDevelopmentSecrets::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
	}//end setUp()

	/**
	 * The step under test, with the owners and password it reads from app config.
	 *
	 * @param string             $owners   The configured owners.
	 * @param string             $password The configured password.
	 * @param array<int, string> $known    The accounts that exist.
	 * @param array<int, string> $admins   The accounts that are administrators.
	 *
	 * @return SeedDemoVaults
	 */
	private function step(string $owners, string $password, array $known=['pieter'], array $admins=[]): SeedDemoVaults {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => match ($key) {
				SeedDemoVaults::OWNERS_KEY => $owners,
				SeedDemoVaults::PASSWORD_KEY => $password,
				default => $default,
			}
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$user  = $this->createMock(originalClassName: IUser::class);
		$users->method('get')->willReturnMap(
			array_map(fn (string $uid): array => [$uid, $user], $known)
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $admins, true));

		return new SeedDemoVaults(
			appConfig: $appConfig,
			userManager: $users,
			groupManager: $groups,
			suiteMapper: $this->suiteMapper,
			suites: $this->suites,
			secrets: $this->secrets,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end step()

	/**
	 * Without both owners and a password nothing is minted.
	 *
	 * @return void
	 */
	public function testNothingWithoutOwnersAndPassword(): void {
		$this->suites->expects($this->never())->method('mintSuite');

		$this->step(owners: '', password: 'demo')->run($this->createMock(originalClassName: IOutput::class));
		$this->step(owners: 'pieter', password: '')->run($this->createMock(originalClassName: IOutput::class));
	}//end testNothingWithoutOwnersAndPassword()

	/**
	 * An administrator and an unknown account never get a demo vault.
	 *
	 * @return void
	 */
	public function testAdministratorsAndUnknownAccountsAreRefused(): void {
		$this->suites->expects($this->never())->method('mintSuite');

		$step = $this->step(owners: 'admin, ghost', password: 'demo', known: ['admin'], admins: ['admin']);
		$step->run($this->createMock(originalClassName: IOutput::class));
	}//end testAdministratorsAndUnknownAccountsAreRefused()

	/**
	 * An account that already has a suite, of any status, is left alone.
	 *
	 * @return void
	 */
	public function testAnExistingVaultIsNeverTouched(): void {
		$this->suiteMapper->method('findByOwner')->with('user', 'pieter')->willReturn([new EncryptionSuite()]);
		$this->suites->expects($this->never())->method('mintSuite');
		$this->secrets->expects($this->never())->method('seedSecrets');

		$this->step(owners: 'pieter', password: 'demo')->run($this->createMock(originalClassName: IOutput::class));
	}//end testAnExistingVaultIsNeverTouched()

	/**
	 * A named account without a suite gets one with the configured password,
	 * filled with the shipped demo folders and secrets.
	 *
	 * @return void
	 */
	public function testANamedAccountGetsTheDemoVault(): void {
		$suite = new EncryptionSuite();
		$demo  = json_decode((string)file_get_contents(self::DEMO_VAULT), true);
		$this->suiteMapper->method('findByOwner')->willReturn([]);
		$this->suites->expects($this->once())->method('mintSuite')
			->with('pieter', 'demo', $this->anything())
			->willReturn($suite);
		$this->secrets->expects($this->once())->method('seedSecrets')
			->with('pieter', $suite, $demo['folders'], $demo['secrets'])
			->willReturn(count($demo['secrets']));

		$this->step(owners: 'pieter', password: 'demo')->run($this->createMock(originalClassName: IOutput::class));
	}//end testANamedAccountGetsTheDemoVault()

	/**
	 * The demo vault holds only values marked as demo data, in its own folders.
	 *
	 * @return void
	 */
	public function testTheDemoVaultHoldsOnlyDemoValues(): void {
		$demo = json_decode((string)file_get_contents(self::DEMO_VAULT), true);

		$this->assertNotEmpty(actual: $demo['secrets']);
		foreach ($demo['secrets'] as $secret) {
			$this->assertStringContainsString(needle: 'demo', haystack: strtolower((string)$secret['key']), message: $secret['name']);
			$this->assertTrue(condition: $secret['folder'] === null || in_array($secret['folder'], $demo['folders'], true), message: $secret['name']);
		}
	}//end testTheDemoVaultHoldsOnlyDemoValues()

	/**
	 * The example set names the cast's demo employee, never an administrator,
	 * and a password that says it is a demo password.
	 *
	 * @return void
	 */
	public function testTheExampleSetNamesTheDemoEmployee(): void {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$config   = array_column($fragment['appConfig'], 'value', 'key');

		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $config[SeedDemoVaults::OWNERS_KEY]);
		$this->assertStringContainsString(needle: 'demo', haystack: strtolower($config[SeedDemoVaults::PASSWORD_KEY]));
	}//end testTheExampleSetNamesTheDemoEmployee()
}//end class
