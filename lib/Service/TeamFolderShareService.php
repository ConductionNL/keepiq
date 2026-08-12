<?php

/**
 * Doriath Team Folder Share Service
 *
 * The DERIVED shares of a team folder: the provenance-linked ShareTarget rows
 * that record which (source secret × recipient) pairs have been fanned out,
 * together with the recipient copies they point at. This class registers a
 * batch of browser-encrypted shares, reports which pairs are still missing for
 * the reconciliation endpoint, and revokes derived shares again — per folder,
 * per member, or for every team folder a departing user held.
 *
 * The server only ever handles ciphertext (ADR-003): the browser encrypts each
 * pair under the recipient's public certificate and this service records it.
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
use OCA\Doriath\Db\BulkGrantShareTargetMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Db\TeamFolder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Registers and revokes the derived shares of a team folder.
 */
class TeamFolderShareService {
	/**
	 * Constructor for TeamFolderShareService.
	 *
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper (per-row reads/writes)
	 * @param BulkGrantShareTargetMapper $bulkGrantMapper The share-target mapper keyed on the grant (cascade)
	 * @param RecipientSecretCopyService $copies The recipient-copy service
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareTargetMapper $shareTargetMapper,
		private BulkGrantShareTargetMapper $bulkGrantMapper,
		private RecipientSecretCopyService $copies,
		private NotificationService $notificationService,
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Register a batch of browser-encrypted fan-out shares. Each row
	 * carries the ciphertext of one (source secret × recipient) pair,
	 * RSA-encrypted in the owner's browser under the recipient's public
	 * certificate. The server creates the recipient's Secret copy AND
	 * the provenance-linked ShareTarget in one transaction — it only
	 * ever handles ciphertext (ADR-003).
	 *
	 * Idempotent: an existing (source, recipient) pair is skipped, so a
	 * retried or duplicated chunk never double-shares.
	 *
	 * In the returned array, `created` is the number of fan-out shares created
	 * (skips excluded) and `rows` describes each one.
	 *
	 * @param TeamFolder $teamFolder The team folder being fanned out
	 * @param array<int,array<string,mixed>> $shares Rows of sourceSecretId, targetUserId,
	 *                                               encryptedKey, encryptedLogin,
	 *                                               encryptedAdditionalFields
	 * @param array<string,bool> $subtreeSecretIds The secret ids inside the folder subtree
	 * @param string $userId The caller (the folder owner)
	 *
	 * @return array{created: int, rows: array<int,array{sourceSecretId: string, targetUserId: string, recipientSecretId: string}>}
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function registerFanOutShares(
		TeamFolder $teamFolder,
		array $shares,
		array $subtreeSecretIds,
		string $userId,
	): array {
		$createdRows = [];
		$newRecipients = [];

		$this->db->beginTransaction();
		try {
			foreach ($shares as $row) {
				$createdRow = $this->createFanOutShare(
					teamFolder: $teamFolder,
					row: $row,
					subtreeSecretIds: $subtreeSecretIds,
					userId: $userId
				);
				if ($createdRow === null) {
					continue;
				}

				$createdRows[] = $createdRow;
				$newRecipients[$createdRow['targetUserId']] = true;
			}//end foreach

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		// One notification per recipient per fan-out run — not per secret.
		foreach (array_keys($newRecipients) as $recipientId) {
			$this->notificationService->notify(
				subject: 'team_folder_shared',
				recipientId: (string)$recipientId,
				params: [
					'teamFolderId' => $teamFolder->getId(),
					'sharedBy' => $userId,
				],
				objectType: 'team_folder',
				objectId: $teamFolder->getId(),
			);
		}

		return [
			'created' => count($createdRows),
			'rows' => $createdRows,
		];
	}//end registerFanOutShares()

	/**
	 * The (secret × recipient) pairs that still have no ShareTarget, so
	 * the browser can encrypt exactly the gap. Idempotent server writes
	 * make a partial fan-out self-heal.
	 *
	 * @param array<int,array{id:string,name:string}> $secrets The subtree secret refs
	 * @param array<int,array{userId:string,certificate:string}> $recipients The eligible recipients
	 *
	 * @return array<int,array{secretId:string,userId:string}>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function missingPairs(array $secrets, array $recipients): array {
		$missing = [];
		foreach ($secrets as $secretRef) {
			foreach ($recipients as $recipient) {
				try {
					$this->shareTargetMapper->findBySourceSecretAndTargetUser(
						sourceSecretId: $secretRef['id'],
						targetUserId: $recipient['userId']
					);
				} catch (DoesNotExistException) {
					$missing[] = [
						'secretId' => $secretRef['id'],
						'userId' => $recipient['userId'],
					];
				}
			}
		}

		return $missing;
	}//end missingPairs()

	/**
	 * Revoke every derived ShareTarget of a team folder (and the
	 * recipient Secret copies they point at).
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 *
	 * @return int Rows revoked
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function revokeForTeamFolder(string $teamFolderId): int {
		$revoked = 0;
		foreach ($this->bulkGrantMapper->findByTeamFolder(teamFolderId: $teamFolderId) as $row) {
			$this->revokeRow(row: $row);
			++$revoked;
		}

		return $revoked;
	}//end revokeForTeamFolder()

	/**
	 * Revoke one user's derived ShareTargets for one team folder,
	 * deleting their recipient Secret copies too.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $targetUserId The recipient losing access
	 *
	 * @return int Rows revoked
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function revokeForMember(string $teamFolderId, string $targetUserId): int {
		$revoked = 0;
		foreach ($this->bulkGrantMapper->findByTeamFolderAndTargetUser(
			teamFolderId: $teamFolderId,
			targetUserId: $targetUserId
		) as $row) {
			$this->revokeRow(row: $row);
			++$revoked;
		}

		return $revoked;
	}//end revokeForMember()

	/**
	 * Revoke every TEAM-FOLDER-derived share held by a user. Direct
	 * (non-team) shares are left untouched.
	 *
	 * @param string $targetUserId The departing user
	 *
	 * @return int Number of derived shares revoked
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	public function revokeTeamSharesForUser(string $targetUserId): int {
		$revoked = 0;
		foreach ($this->shareTargetMapper->findByTargetUser(targetUserId: $targetUserId) as $row) {
			if ($row->getTeamFolderId() === null || $row->getTeamFolderId() === '') {
				continue;
			}

			$this->revokeRow(row: $row);
			++$revoked;
		}

		return $revoked;
	}//end revokeTeamSharesForUser()

	/**
	 * Materialise one fan-out share row, or report a skip.
	 *
	 * A row is skipped (null) when it is incomplete, when the source secret
	 * is outside this folder's subtree, when the target is the folder owner,
	 * when the share already exists, or when the recipient has no active
	 * encryption suite.
	 *
	 * @param TeamFolder $teamFolder The team folder being fanned out
	 * @param array<string,mixed> $row One {sourceSecretId, targetUserId, encryptedKey, ...} row
	 * @param array<string,bool> $subtreeSecretIds The secret ids inside the folder subtree
	 * @param string $userId The caller (the folder owner)
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}|null
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	private function createFanOutShare(
		TeamFolder $teamFolder,
		array $row,
		array $subtreeSecretIds,
		string $userId,
	): ?array {
		$sourceSecretId = (string)($row['sourceSecretId'] ?? '');
		$targetUserId = (string)($row['targetUserId'] ?? '');
		$encryptedKey = (string)($row['encryptedKey'] ?? '');

		if ($sourceSecretId === '' || $targetUserId === '' || $encryptedKey === '') {
			return null;
		}

		// Only secrets inside this team folder's subtree may carry
		// its provenance, and never a copy for the owner.
		if (isset($subtreeSecretIds[$sourceSecretId]) === false
			|| $targetUserId === $teamFolder->getOwnerId()
		) {
			return null;
		}

		try {
			$this->shareTargetMapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $sourceSecretId,
				targetUserId: $targetUserId
			);
			return null;
		} catch (DoesNotExistException) {
			// No existing share — create below.
		}

		$copy = $this->copies->create(
			sourceSecretId: $sourceSecretId,
			targetUserId: $targetUserId,
			encryptedKey: $encryptedKey,
			encryptedLogin: $this->nullableString(value: $row['encryptedLogin'] ?? null),
			encryptedExtras: $this->nullableString(value: $row['encryptedAdditionalFields'] ?? null),
		);
		if ($copy === null) {
			// Recipient without an active suite — skipped silently.
			return null;
		}

		$entity = new ShareTarget();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSourceSecretId($sourceSecretId);
		$entity->setTargetUserId($targetUserId);
		$entity->setSecretId($copy->getId());
		$entity->setTeamFolderId($teamFolder->getId());
		$entity->setCreatedBy($userId);
		$entity->setCreatedAt(new DateTime());
		$this->shareTargetMapper->insert($entity);

		return [
			'sourceSecretId' => $sourceSecretId,
			'targetUserId' => $targetUserId,
			'recipientSecretId' => $copy->getId(),
		];
	}//end createFanOutShare()

	/**
	 * Revoke one ShareTarget row: the recipient's Secret copy goes first,
	 * then the provenance row itself.
	 *
	 * @param ShareTarget $row The share-target row
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	private function revokeRow(ShareTarget $row): void {
		$this->copies->deleteById(secretId: $row->getSecretId());
		$this->shareTargetMapper->delete($row);
	}//end revokeRow()

	/**
	 * Normalize an optional string value: empty becomes null.
	 *
	 * @param mixed $value The candidate value
	 *
	 * @return string|null
	 *
	 * @spec exclude Value normalisation helper with no spec behaviour of its own.
	 */
	private function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = (string)$value;

		if ($string === '') {
			return null;
		}

		return $string;
	}//end nullableString()
}//end class
