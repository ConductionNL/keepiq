<?php

/**
 * Keepiq Team Folder Offboarding Service
 *
 * Admin offboarding of a departing user (team-folder-sharing §2.5). Two ordered
 * steps: every team-folder-derived share the leaver holds is revoked, then every
 * team secret the leaver OWNS is transferred to the successor. Secrets whose
 * successor holds no recipient copy yet are reported as skipped — the admin
 * re-runs the offboarding after adding the successor to the folder.
 *
 * The action is restricted to Nextcloud instance admins and members of the
 * `vault_admin` group, mirroring DelegationService.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Runs the admin offboarding of a departing user's team-folder access.
 */
class TeamFolderOffboardingService {
	/**
	 * The Nextcloud group whose members may run the offboarding action
	 * (in addition to instance admins). Mirrors DelegationService.
	 *
	 * @var string
	 */
	private const VAULT_ADMIN_GROUP = 'vault_admin';

	/**
	 * Constructor for TeamFolderOffboardingService.
	 *
	 * @param TeamFolderShareService $shares The derived-share service (revocation)
	 * @param TeamSecretTransferService $transfers The team-secret transfer service
	 * @param IGroupManager $groupManager The Nextcloud group manager
	 * @param LoggerInterface $logger The logger
	 * @param TeamFolderAuditor $audit The team-folder auditor
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderShareService $shares,
		private TeamSecretTransferService $transfers,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private TeamFolderAuditor $audit,
	) {
	}//end __construct()

	/**
	 * Revoke every team-folder-derived share held by the leaving user, then
	 * transfer each team secret the leaver OWNS to the successor.
	 *
	 * @param string $leavingUserId The user being offboarded
	 * @param string $successorUserId The user taking over owned team secrets
	 * @param string $adminId The caller (instance admin or vault_admin)
	 *
	 * @return array{revoked:int,transferred:int,skipped:array<int,string>}
	 *
	 * @throws InvalidArgumentException On invalid input / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	public function offboard(string $leavingUserId, string $successorUserId, string $adminId): array {
		$this->assertOffboardingAdmin(userId: $adminId);

		if ($leavingUserId === '' || $successorUserId === '') {
			throw new InvalidArgumentException(message: 'leavingUserId and successorUserId are required');
		}

		if ($leavingUserId === $successorUserId) {
			throw new InvalidArgumentException(message: 'Successor must differ from the leaving user');
		}

		// Step 1 — revoke every team-folder-derived share held by the leaver.
		$revoked = $this->shares->revokeTeamSharesForUser(targetUserId: $leavingUserId);

		// Step 2 — transfer team secrets the leaver owns to the successor.
		$transfer = $this->transfers->transfer(
			leavingUserId: $leavingUserId,
			successorUserId: $successorUserId,
			adminId: $adminId
		);
		$transferred = $transfer['transferred'];
		$skipped = $transfer['skipped'];

		$this->logger->info(
			'Offboarded ' . $leavingUserId . ': revoked ' . $revoked . ' team shares, transferred '
			. $transferred . ' secrets to ' . $successorUserId,
			['app' => 'keepiq']
		);

		$this->audit->offboarded(
			adminId: $adminId,
			leavingUserId: $leavingUserId,
			successorUserId: $successorUserId,
			revoked: $revoked,
			transferred: $transferred,
		);

		return [
			'revoked' => $revoked,
			'transferred' => $transferred,
			'skipped' => $skipped,
		];
	}//end offboard()

	/**
	 * Assert the caller may run the offboarding action: a Nextcloud
	 * instance admin or a member of the vault_admin group.
	 *
	 * @param string $userId The candidate admin
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When unauthorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	private function assertOffboardingAdmin(string $userId): void {
		if ($this->groupManager->isAdmin($userId) === true) {
			return;
		}

		if ($this->groupManager->isInGroup($userId, self::VAULT_ADMIN_GROUP) === true) {
			return;
		}

		throw new InvalidArgumentException(
			message: 'Offboarding requires instance admin or vault_admin membership'
		);
	}//end assertOffboardingAdmin()
}//end class
