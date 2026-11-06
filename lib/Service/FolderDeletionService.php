<?php

/**
 * Keepiq Folder Deletion Service
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
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ConflictException;
use OCP\AppFramework\Db\TTransactional;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Runs the folder deletion protocol and audits the cascade.
 */
class FolderDeletionService {
	use TTransactional;

	/**
	 * Constructor for FolderDeletionService.
	 *
	 * @param FolderMapper $mapper The folder mapper
	 * @param SecretMapper $secretMapper The secret mapper (counts)
	 * @param FolderCascadeService $cascade The subtree cascade mechanics
	 * @param IDBConnection $db The database connection (transactions)
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
		private IDBConnection $db,
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
	 * Delete a folder, applying the appropriate protocol. Every refusal comes
	 * before the first write, and the writes commit together or not at all;
	 * attachment blobs are files, so a rollback cannot bring those back.
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
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function delete(Folder $folder, ?string $cascade, ?array $resolution, string $userId): void {
		$id = $folder->getId();
		$children = $this->mapper->findChildren($id);
		$secretCount = $this->secretMapper->countByFolder($id);
		$hasSecrets = ($secretCount > 0);

		if ($children === [] && $hasSecrets === false) {
			// Empty folder — direct delete. No cascade event.
			$this->mapper->delete($folder);
			$this->logger->info("Keepiq: empty folder {$id} deleted by {$userId}");
			return;
		}

		if ($children !== []) {
			$plan = $this->resolutionPlan(folder: $folder, children: $children, resolution: $resolution);
			$purgeSecrets = (($resolution['directSecrets'] ?? 'move') === 'delete');
			$this->atomic(
				fn: fn () => $this->deleteWithSubfolders(
					folder: $folder,
					children: $children,
					plan: $plan,
					purgeSecrets: $purgeSecrets,
					userId: $userId
				),
				db: $this->db
			);
		}

		if ($children === []) {
			// Non-empty leaf folder (secrets, no subfolders) — requires cascade.
			if ($cascade !== 'delete' && $cascade !== 'move') {
				throw new ConflictException(message: 'Folder is not empty');
			}

			$this->atomic(fn: fn () => $this->deleteLeafWithSecrets(folder: $folder, cascade: $cascade), db: $this->db);
		}

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::FOLDER_DELETED_CASCADE,
				objectType: 'folder',
				objectId: $id,
				objectName: $folder->getName(),
				metadata: [
					'secretCount' => $secretCount,
					'subfolderCount' => count($children),
				],
			)
		);
	}//end delete()

	/**
	 * Delete a leaf folder that contains secrets but no subfolders.
	 *
	 * @param Folder $folder The folder to delete
	 * @param string $cascade The cascade mode (delete or move)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	private function deleteLeafWithSecrets(Folder $folder, string $cascade): void {
		if ($cascade === 'delete') {
			$this->cascade->purgeSecretsIn(folderId: $folder->getId());
		}

		if ($cascade === 'move') {
			$this->cascade->moveSecretsFrom(folderId: $folder->getId(), targetId: $folder->getParentId());
		}

		$this->mapper->delete($folder);
		$this->logger->info("Keepiq: leaf folder {$folder->getId()} deleted (cascade={$cascade})");
	}//end deleteLeafWithSecrets()

	/**
	 * Read the resolution plan of a folder with subfolders, refusing it
	 * before anything is written.
	 *
	 * @param Folder $folder The folder to delete
	 * @param Folder[] $children The direct subfolders
	 * @param array<string,mixed>|null $resolution The resolution body
	 *
	 * @return array<string,string> The action per direct subfolder ID
	 *
	 * @throws ConflictException When no resolution body is supplied
	 * @throws InvalidArgumentException When the plan is incomplete or invalid
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	private function resolutionPlan(Folder $folder, array $children, ?array $resolution): array {
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

		$actions = [];
		foreach ($children as $child) {
			$action = $plan[$child->getId()];
			if (is_string($action) === false) {
				$action = '';
			}

			$this->cascade->assertSubfolderAction(subfolder: $child, action: $action, deleted: $folder);
			$actions[$child->getId()] = $action;
		}

		return $actions;
	}//end resolutionPlan()

	/**
	 * Delete a folder that contains subfolders, using a checked resolution plan.
	 *
	 * @param Folder $folder The folder to delete
	 * @param Folder[] $children The direct subfolders
	 * @param array<string,string> $plan The action per direct subfolder ID
	 * @param bool $purgeSecrets Whether the folder's direct secrets are deleted instead of moved
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	private function deleteWithSubfolders(
		Folder $folder,
		array $children,
		array $plan,
		bool $purgeSecrets,
		string $userId,
	): void {
		$parentId = $folder->getParentId();

		if ($purgeSecrets === true) {
			$this->cascade->purgeSecretsIn(folderId: $folder->getId());
		}

		if ($purgeSecrets === false) {
			$this->cascade->moveSecretsFrom(folderId: $folder->getId(), targetId: $parentId);
		}

		foreach ($children as $child) {
			$this->cascade->applySubfolderAction(
				subfolder: $child,
				action: $plan[$child->getId()],
				deletedParentId: $parentId
			);
		}

		$this->mapper->delete($folder);
		$this->logger->info("Keepiq: folder {$folder->getId()} deleted with resolution by {$userId}");
	}//end deleteWithSubfolders()
}//end class
