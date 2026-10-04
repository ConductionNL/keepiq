<?php

/**
 * Unit tests for AdminAreaAuthorizer (admin-scoped-roles §1.3).
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

use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCA\Keepiq\Tests\Support\AdminAreaFixture;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Admin, delegated user, vault_admin member and outsider.
 */
class AdminAreaAuthorizerTest extends TestCase {
	use AdminAreaFixture;

	/**
	 * A group manager answering for one admin and one member of the former
	 * vault_admin group, which no longer grants anything.
	 *
	 * @return IGroupManager
	 */
	private function groupDirectory(): IGroupManager {
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'legacy' && $group === 'vault_admin'
		);

		return $groups;
	}//end groupDirectory()

	/**
	 * An instance admin holds every area without any delegation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function testAnInstanceAdminHoldsEveryArea(): void {
		$areas = $this->areaAuthorizer(groupManager: $this->groupDirectory(), delegated: []);

		$this->assertSame(array_keys(AdminAreaAuthorizer::AREAS), $areas->areasOf(userId: 'root'));
	}//end testAnInstanceAdminHoldsEveryArea()

	/**
	 * A delegated user holds exactly the delegated area.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function testADelegatedUserHoldsOnlyTheDelegatedArea(): void {
		$areas = $this->areaAuthorizer(groupManager: $this->groupDirectory(), delegated: [AuditAdminSettings::class]);

		$this->assertTrue($areas->holds(userId: 'auditor', areaClass: AuditAdminSettings::class));
		$this->assertFalse($areas->holds(userId: 'auditor', areaClass: PolicyAdminSettings::class));
		$this->assertSame(['audit'], $areas->areasOf(userId: 'auditor'));
	}//end testADelegatedUserHoldsOnlyTheDelegatedArea()

	/**
	 * Every area class, keyed by area key, for the alias refusal.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function everyArea(): array {
		$cases = [];
		foreach (AdminAreaAuthorizer::AREAS as $key => $class) {
			$cases[$key] = [$class];
		}

		return $cases;
	}//end everyArea()

	/**
	 * A vault_admin member without a delegation holds no area: the alias for
	 * People is gone (red before: People answered true).
	 *
	 * @param string $areaClass The area class asked about
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#4.1
	 */
	#[DataProvider('everyArea')]
	public function testAVaultAdminMemberWithoutADelegationHoldsNoArea(string $areaClass): void {
		$areas = $this->areaAuthorizer(groupManager: $this->groupDirectory(), delegated: []);

		$this->assertFalse($areas->holds(userId: 'legacy', areaClass: $areaClass));
		$this->assertSame([], $areas->areasOf(userId: 'legacy'));
	}//end testAVaultAdminMemberWithoutADelegationHoldsNoArea()

	/**
	 * An outsider holds nothing, and an unknown class is never held, even by
	 * an admin.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function testAnOutsiderHoldsNothingAndUnknownClassesAreNeverHeld(): void {
		$areas = $this->areaAuthorizer(groupManager: $this->groupDirectory(), delegated: []);

		$this->assertSame([], $areas->areasOf(userId: 'bob'));
		$this->assertFalse($areas->holds(userId: 'root', areaClass: 'OCA\Keepiq\Settings\Nothing'));
		$this->assertFalse($areas->holds(userId: '', areaClass: AdminSettings::class));
	}//end testAnOutsiderHoldsNothingAndUnknownClassesAreNeverHeld()

	/**
	 * A delegation is matched on the exact class: a General delegation does
	 * not grant People, and the generic AppHost class grants nothing (the
	 * binding defect POLICY.md #774 note a).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function testADelegationMatchesTheExactClass(): void {
		$areas = $this->areaAuthorizer(groupManager: $this->groupDirectory(), delegated: [AdminSettings::class]);

		$this->assertTrue($areas->holds(userId: 'helpdesk', areaClass: AdminSettings::class));
		$this->assertFalse($areas->holds(userId: 'helpdesk', areaClass: PeopleAdminSettings::class));
	}//end testADelegationMatchesTheExactClass()

	/**
	 * A failing delegation lookup grants nothing (fail closed).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function testAFailingLookupGrantsNothing(): void {
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($this->createStub(IUser::class));
		$settings = $this->createStub(IManager::class);
		$settings->method('getAllowedAdminSettings')->willThrowException(new RuntimeException('db down'));

		$areas = new AdminAreaAuthorizer(
			groupManager: $this->groupDirectory(),
			userManager: $users,
			settingsManager: $settings,
			logger: $this->createStub(LoggerInterface::class),
		);

		$this->assertFalse($areas->holds(userId: 'auditor', areaClass: AuditAdminSettings::class));
		$this->assertTrue($areas->holds(userId: 'root', areaClass: AuditAdminSettings::class));
	}//end testAFailingLookupGrantsNothing()
}//end class
