<?php

/**
 * The handover button, the handover enforcement and the offboarding
 * enforcement agree for every kind of user (admin-scoped-roles §2.4, D5).
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Service\DelegationAuthorizer;
use OCA\Keepiq\Service\TeamFolderAuditor;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamFolderOffboardingService;
use OCA\Keepiq\Service\TeamFolderShareService;
use OCA\Keepiq\Service\TeamSecretTransferService;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Tests\Support\AdminAreaFixture;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Instance admin, People delegate, vault_admin member, other delegate, outsider.
 */
class PeopleAreaAgreementTest extends TestCase {
	use AdminAreaFixture;

	/**
	 * User kind, delegated areas, expected answer.
	 *
	 * @return array<string,array{0:string,1:string[],2:bool}>
	 */
	public static function users(): array {
		return [
			'instance admin' => ['root', [], true],
			'people delegate' => ['helpdesk', [PeopleAdminSettings::class], true],
			'vault_admin member without a delegation' => ['legacy', [], false],
			'audit delegate' => ['auditor', [AuditAdminSettings::class], false],
			'outsider' => ['bob', [], false],
		];
	}//end users()

	/**
	 * The capabilities flag, the handover guard and the offboarding guard
	 * give the same answer (red before: an instance admin outside
	 * vault_admin was offered no handover yet could offboard, and a People
	 * delegate could do neither). A vault_admin member without a delegation
	 * is refused by all three (red before #1043: the alias let them through).
	 *
	 * @param string $uid The user
	 * @param string[] $delegated The areas delegated to the user's groups
	 * @param bool $expected Whether the user may act
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.4
	 */
	#[DataProvider('users')]
	public function testTheFlagAndBothGuardsAgree(string $uid, array $delegated, bool $expected): void {
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $id === 'root');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $id, string $group): bool => $id === 'legacy' && $group === 'vault_admin'
		);
		$areas = $this->areaAuthorizer(groupManager: $groups, delegated: $delegated);

		$authorizer = new DelegationAuthorizer(secretMapper: $this->createStub(SecretMapper::class), areas: $areas);
		$this->assertSame($expected, $authorizer->canHandover(userId: $uid), 'capabilities flag');

		$handoverAllowed = true;
		try {
			$authorizer->requireHandoverAdmin(userId: $uid);
		} catch (InvalidArgumentException) {
			$handoverAllowed = false;
		}

		$this->assertSame($expected, $handoverAllowed, 'handover enforcement');

		$offboarding = new TeamFolderOffboardingService(
			shares: $this->createStub(TeamFolderShareService::class),
			transfers: $this->createStub(TeamSecretTransferService::class),
			areas: $areas,
			logger: $this->createStub(LoggerInterface::class),
			audit: $this->createStub(TeamFolderAuditor::class),
			memberMapper: $this->createStub(TeamFolderMemberMapper::class),
			memberships: $this->createStub(TeamFolderMembershipResolver::class),
		);
		try {
			// Empty user ids: an authorized caller gets past the guard and is
			// refused for the missing ids instead.
			$offboarding->offboard(leavingUserId: '', successorUserId: '', adminId: $uid);
			$this->fail('offboard() with empty ids must throw');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				$expected,
				str_contains($e->getMessage(), 'are required'),
				'offboarding enforcement: ' . $e->getMessage()
			);
		}
	}//end testTheFlagAndBothGuardsAgree()
}//end class
