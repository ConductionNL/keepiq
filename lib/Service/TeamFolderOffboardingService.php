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
 * The action is restricted to Nextcloud instance admins and holders of the
 * People and offboarding admin area, mirroring DelegationService.
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
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use Psr\Log\LoggerInterface;

/**
 * Runs the admin offboarding of a departing user's team-folder access.
 */
class TeamFolderOffboardingService {
	/**
	 * Constructor for TeamFolderOffboardingService.
	 *
	 * @param TeamFolderShareService $shares The derived-share service (revocation)
	 * @param TeamSecretTransferService $transfers The team-secret transfer service
	 * @param AdminAreaAuthorizer $areas The People and offboarding area check
	 * @param LoggerInterface $logger The logger
	 * @param TeamFolderAuditor $audit The team-folder auditor
	 * @param TeamFolderMemberMapper $memberMapper The team-folder member rows
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderShareService $shares,
		private TeamSecretTransferService $transfers,
		private AdminAreaAuthorizer $areas,
		private LoggerInterface $logger,
		private TeamFolderAuditor $audit,
		private TeamFolderMemberMapper $memberMapper,
	) {
	}//end __construct()

	/**
	 * Revoke every team-folder-derived share held by the leaving user,
	 * remove the leaver's direct team-folder memberships, then transfer each
	 * team secret the leaver OWNS to the successor.
	 *
	 * @param string $leavingUserId The user being offboarded
	 * @param string $successorUserId The user taking over owned team secrets
	 * @param string $adminId The caller (instance admin or People area holder)
	 *
	 * @return array{revoked:int,removedMemberships:int,transferred:int,skipped:array<int,string>}
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

		// Step 1b — remove the leaver's direct member rows. Revoking the shares
		// alone left the leaver a member, so the owner's next "Share now" for
		// pending members handed the secrets straight back (#747). A membership
		// through a group is the group's business and stays.
		$removedMemberships = $this->removeDirectMemberships(userId: $leavingUserId);

		// Step 2 — transfer team secrets the leaver owns to the successor.
		$transfer = $this->transfers->transfer(
			leavingUserId: $leavingUserId,
			successorUserId: $successorUserId,
			adminId: $adminId
		);
		$transferred = $transfer['transferred'];
		$skipped = $transfer['skipped'];

		$this->logger->info(
			'Offboarded ' . $leavingUserId . ': revoked ' . $revoked . ' team shares, removed '
			. $removedMemberships . ' team-folder memberships, transferred '
			. $transferred . ' secrets to ' . $successorUserId,
			['app' => 'keepiq']
		);

		$this->audit->offboarded(
			adminId: $adminId,
			leavingUserId: $leavingUserId,
			successorUserId: $successorUserId,
			revoked: $revoked,
			transferred: $transferred,
			removedMemberships: $removedMemberships,
		);

		return [
			'revoked' => $revoked,
			'removedMemberships' => $removedMemberships,
			'transferred' => $transferred,
			'skipped' => $skipped,
		];
	}//end offboard()

	/**
	 * Delete every direct user-type team-folder membership of a user.
	 *
	 * @param string $userId The user being offboarded
	 *
	 * @return int The number of member rows removed
	 *
	 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-admin-offboarding
	 */
	private function removeDirectMemberships(string $userId): int {
		$removed = 0;
		foreach ($this->memberMapper->findUserMemberships(userId: $userId) as $membership) {
			$this->memberMapper->delete(entity: $membership);
			$removed++;
		}

		return $removed;
	}//end removeDirectMemberships()

	/**
	 * Assert the caller may run the offboarding action: an instance admin or
	 * a holder of the People and offboarding area (admin-scoped-roles D5).
	 *
	 * @param string $userId The candidate admin
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When unauthorized
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.4
	 */
	private function assertOffboardingAdmin(string $userId): void {
		if ($this->areas->holds(userId: $userId, areaClass: PeopleAdminSettings::class) === true) {
			return;
		}

		throw new InvalidArgumentException(
			message: 'Offboarding requires the People and offboarding admin area'
		);
	}//end assertOffboardingAdmin()
}//end class
