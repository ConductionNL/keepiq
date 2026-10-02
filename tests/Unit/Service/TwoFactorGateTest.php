<?php

/**
 * Unit tests for TwoFactorGate (admin-vault-policies §1.3, §3.1) and the
 * admin-only gap count endpoint.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Service\TwoFactorGate;
use OCA\Keepiq\Service\VaultPolicyService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * frank has only backup codes, tina has TOTP; both are in group staff,
 * olaf (no provider) is not.
 */
class TwoFactorGateTest extends TestCase {

	private TwoFactorGate $gate;

	/**
	 * Wire the gate over mocked Nextcloud services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$states = [
			'frank' => ['backup_codes' => true],
			'tina' => ['totp' => true, 'backup_codes' => true],
			'olaf' => [],
		];
		$users = [];
		foreach (array_keys($states) as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[$uid] = $user;
		}

		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturnCallback(
			static fn (IUser $user): array => $states[$user->getUID()]
		);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid) => ($users[$uid] ?? null));
		$userManager->method('callForSeenUsers')->willReturnCallback(static function (\Closure $callback) use ($users): void {
			foreach ($users as $user) {
				$callback($user);
			}
		});
		$staff = $this->createMock(IGroup::class);
		$staff->method('getUsers')->willReturn([$users['frank'], $users['tina']]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid) => ($gid === 'staff' ? $staff : null));

		$policies = $this->createMock(VaultPolicyService::class);
		$policies->method('appliesTo')->willReturn(true);

		$this->gate = new TwoFactorGate(policies: $policies, registry: $registry, userManager: $userManager, groupManager: $groups);
	}//end setUp()

	/**
	 * Backup codes alone do not count; TOTP does; an unknown user is blocked.
	 *
	 * @return void
	 */
	public function testBackupCodesAloneDoNotCount(): void {
		$this->assertTrue($this->gate->blocks(userId: 'frank'));
		$this->assertFalse($this->gate->blocks(userId: 'tina'));
		$this->assertTrue($this->gate->blocks(userId: 'ghost'));
	}//end testBackupCodesAloneDoNotCount()

	/**
	 * The gap count for a group scope and for everyone.
	 *
	 * @return void
	 */
	public function testGapReport(): void {
		$this->assertSame(['inScope' => 2, 'withoutTwoFactor' => 1], $this->gate->gapReport(groupIds: ['staff']));
		$this->assertSame(['inScope' => 3, 'withoutTwoFactor' => 2], $this->gate->gapReport(groupIds: []));
	}//end testGapReport()

	/**
	 * The gap endpoint is refused to a regular user: the middleware reads
	 * the admin-setting guard and nothing widens it.
	 *
	 * @return void
	 */
	public function testGapEndpointIsAdminOnly(): void {
		$method = new ReflectionMethod(SettingsController::class, 'twoFactorGaps');

		$this->assertSame([AdminSettings::class], $method->getAttributes(AuthorizedAdminSetting::class)[0]->getArguments());
		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
	}//end testGapEndpointIsAdminOnly()
}//end class
