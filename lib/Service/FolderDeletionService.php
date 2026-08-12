<?php

/**
 * Doriath Folder Deletion Service
 *
 * The three-mode deletion protocol of the folder tree, split out of
 * FolderService: an empty folder goes directly, a non-empty LEAF folder needs
 * the shorthand `cascade=delete|move`, and a folder that contains subfolders
 * needs a per-subfolder resolution plan. It also builds the children payload
 * the resolution dialog is driven from.
 *
 * Ownership of the folder is checked by the caller (FolderService::getOwned)
 * before any method here runs — this class receives a resolved Folder.
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

use InvalidArgumentException;
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Exception\ConflictException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Runs the folder deletion protocol and audits the cascade.
 */
class FolderDeletionService {
	/**
	 * Constructor for FolderDeletionService.
	 *
	 * @param FolderMapper $mapper The folder mapper
	 * @param SecretMapper $secretMapper The secret mapper (counts)
	 * @param FolderCascadeService $cascade The subtree cascade mechanics
	 * @param LoggerInterface $logger The logger interface
	 * @param IEventDispatcher|null $eventDispatcher The event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private FolderMapper $mapper,
		private SecretMapper $secretMapper,
		private FolderCascadeService $cascade,
		private LoggerInterface $logger,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Dispatch a typed audit event, fail-soft.
	 *
	 * @param AuditEvent $event The audit event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
	 */
	private function dispatchAudit(AuditEvent $event): void {
		$this->eventDispatcher?->dispatchTyped($event);
	}//end dispatchAudit()

	/**
	 * Build the children payload for the resolution dialog: the folder's
	 * direct secret count and its direct subfolders with recursive counts.
	 *
	 * @param string $id The folder ID (ownership already verified)
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function describeChildren(string $id): array {
		$subfolders = [];
		foreach ($this->mapper->findChildren($id) as $child) {
			$subtreeIds = $this->mapper->getSubtreeIds($child->getId());
			$subfolders[] = [
				'id' => $child->getId(),
				'name' => $child->getName(),
				'secretCount' => $this->secretMapper->countByFolderIds($subtreeIds),
				'subfolderCount' => (count($subtreeIds) - 1),
			];
		}

		return [
			'directSecretCount' => $this->secretMapper->countByFolder($id),
			'subfolders' => $subfolders,
		];
	}//end describeChildren()

	/**
	 * Delete a folder, applying the appropriate protocol.
	 *
	 * @param Folder $folder The folder to delete (ownership verified)
	 * @param string|null $cascade The shorthand cascade mode (delete/move) for leaf folders
	 * @param array<string,mixed>|null $resolution The per-subfolder resolution body
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws ConflictException When a non-empty folder is deleted without a plan
	 * @throws InvalidArgumentException When the resolution body is incomplete
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function delete(Folder $folder, ?string $cascade, ?array $resolution, string $userId): void {
		$id = $folder->getId();
		$children = $this->mapper->findChildren($id);
		$secretCount = $this->secretMapper->countByFolder($id);
		$hasSecrets = ($secretCount > 0);

		if ($children === [] && $hasSecrets === false) {
			// Empty folder — direct delete. No cascade event.
			$this->mapper->delete($folder);
			$this->logger->info("Doriath: empty folder {$id} deleted by {$userId}");
			return;
		}

		$folderName = $folder->getName();
		$subfolderCount = count($children);

		if ($children !== []) {
			$this->deleteWithSubfolders(folder: $folder, children: $children, resolution: $resolution, userId: $userId);

			$this->dispatchAudit(
				event: $this->auditEvents->forUser(
					actorId: $userId,
					eventType: AuditEventTypes::FOLDER_DELETED_CASCADE,
					objectType: 'folder',
					objectId: $id,
					objectName: $folderName,
					metadata: [
						'secretCount' => $secretCount,
						'subfolderCount' => $subfolderCount,
					],
				)
			);
			return;
		}

		// Non-empty leaf folder (secrets, no subfolders) — requires cascade.
		$this->deleteLeafWithSecrets(folder: $folder, cascade: $cascade);

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::FOLDER_DELETED_CASCADE,
				objectType: 'folder',
				objectId: $id,
				objectName: $folderName,
				metadata: [
					'secretCount' => $secretCount,
					'subfolderCount' => 0,
				],
			)
		);
	}//end delete()

	/**
	 * Delete a leaf folder that contains secrets but no subfolders.
	 *
	 * @param Folder $folder The folder to delete
	 * @param string|null $cascade The cascade mode (delete or move)
	 *
	 * @return void
	 *
	 * @throws ConflictException When no cascade mode is given
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	private function deleteLeafWithSecrets(Folder $folder, ?string $cascade): void {
		if ($cascade !== 'delete' && $cascade !== 'move') {
			throw new ConflictException(message: 'Folder is not empty');
		}

		if ($cascade === 'delete') {
			$this->cascade->purgeSecretsIn(folderId: $folder->getId());
		}

		if ($cascade === 'move') {
			$this->cascade->moveSecretsFrom(folderId: $folder->getId(), targetId: $folder->getParentId());
		}

		$this->mapper->delete($folder);
		$this->logger->info("Doriath: leaf folder {$folder->getId()} deleted (cascade={$cascade})");
	}//end deleteLeafWithSecrets()

	/**
	 * Delete a folder that contains subfolders, using the resolution plan.
	 *
	 * @param Folder $folder The folder to delete
	 * @param Folder[] $children The direct subfolders
	 * @param array<string,mixed>|null $resolution The resolution body
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws ConflictException When no resolution body is supplied
	 * @throws InvalidArgumentException When the plan is incomplete or invalid
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	private function deleteWithSubfolders(Folder $folder, array $children, ?array $resolution, string $userId): void {
		if ($resolution === null || isset($resolution['subfolders']) === false) {
			throw new ConflictException(message: 'Folder contains subfolders — resolution required');
		}

		$plan = $resolution['subfolders'];
		if (is_array($plan) === false) {
			throw new InvalidArgumentException('Invalid resolution body');
		}

		// Every direct subfolder must be accounted for.
		foreach ($children as $child) {
			if (array_key_exists($child->getId(), $plan) === false) {
				throw new InvalidArgumentException('Resolution body is missing one or more subfolders');
			}
		}

		$parentId = $folder->getParentId();

		// Direct secrets of the deleted folder follow the directSecrets action.
		$directAction = $resolution['directSecrets'] ?? 'move';
		if ($directAction === 'delete') {
			$this->cascade->purgeSecretsIn(folderId: $folder->getId());
		}

		if ($directAction !== 'delete') {
			$this->cascade->moveSecretsFrom(folderId: $folder->getId(), targetId: $parentId);
		}

		foreach ($children as $child) {
			$action = $plan[$child->getId()];
			$this->cascade->applySubfolderAction(
				subfolder: $child,
				action: (string)$action,
				deletedParentId: $parentId
			);
		}

		$this->mapper->delete($folder);
		$this->logger->info("Doriath: folder {$folder->getId()} deleted with resolution by {$userId}");
	}//end deleteWithSubfolders()
}//end class
