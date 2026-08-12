<?php

/**
 * Doriath Team Secret Transfer Service
 *
 * Step 2 of admin offboarding (team-folder-sharing §2.5): every TEAM secret the
 * leaving user owns is handed to the successor through the existing permanent
 * delegation mechanics — a SecretDelegation row plus an owner reassignment,
 * mirroring the account-deletion transfer path.
 *
 * Delegation PROMOTES an existing recipient copy, so a secret the successor
 * holds no copy of yet cannot be transferred; those are reported as skipped and
 * the admin re-runs the offboarding after adding the successor to the folder.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateTime;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Db\TeamFolderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Transfers a leaving user's team secrets to their successor.
 */
class TeamSecretTransferService {
	/**
	 * Constructor for TeamSecretTransferService.
	 *
	 * @param TeamFolderMapper $mapper The team-folder mapper
	 * @param TeamFolderMembershipResolver $memberships The membership resolver (subtree walk)
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper
	 * @param SecretDelegationMapper $delegationMapper The delegation mapper
	 * @param SecretMapper $secretMapper The secret mapper
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderMapper $mapper,
		private TeamFolderMembershipResolver $memberships,
		private ShareTargetMapper $shareTargetMapper,
		private SecretDelegationMapper $delegationMapper,
		private SecretMapper $secretMapper,
	) {
	}//end __construct()

	/**
	 * Delegate and reassign every team secret the leaving user owns to the
	 * successor. A secret the successor holds no recipient copy of is
	 * reported as skipped rather than transferred, because delegation
	 * promotes an existing copy.
	 *
	 * @param string $leavingUserId The departing user
	 * @param string $successorUserId The successor taking ownership
	 * @param string $adminId The admin running the offboarding
	 *
	 * @return array{transferred:int,skipped:array<int,string>}
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	public function transfer(string $leavingUserId, string $successorUserId, string $adminId): array {
		$transferred = 0;
		$skipped = [];
		foreach ($this->mapper->findByOwner(ownerId: $leavingUserId) as $teamFolder) {
			foreach ($this->memberships->subtreeSecretRefs(teamFolder: $teamFolder) as $secretRef) {
				try {
					$this->shareTargetMapper->findBySourceSecretAndTargetUser(
						sourceSecretId: $secretRef['id'],
						targetUserId: $successorUserId
					);
				} catch (DoesNotExistException) {
					// Delegation promotes an existing recipient copy; the
					// successor holds none for this secret yet.
					$skipped[] = $secretRef['id'];
					continue;
				}

				$delegation = new SecretDelegation();
				$delegation->setId(Uuid::uuid4()->toString());
				$delegation->setSecretId($secretRef['id']);
				$delegation->setOriginalOwnerId($leavingUserId);
				$delegation->setDelegatedTo($successorUserId);
				$delegation->setDelegatedAt(new DateTime());
				$delegation->setInitiatedBy($adminId);
				$delegation->setIsPermanent(true);
				$this->delegationMapper->insert($delegation);

				$this->secretMapper->reassignOwner(
					secretId: $secretRef['id'],
					newOwnerId: $successorUserId
				);
				++$transferred;
			}//end foreach
		}//end foreach

		return [
			'transferred' => $transferred,
			'skipped' => $skipped,
		];
	}//end transfer()
}//end class
