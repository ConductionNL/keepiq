<?php

/**
 * Keepiq Share Save Service
 *
 * `PUT /secrets/{id}/share-save`: a shared secret, every local copy and every
 * partner share saved in one database transaction, from ciphertext the
 * client made for each holder. Partner instances are told after the commit.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSaveHistory;
use OCA\Keepiq\Service\ShareSave\ShareSavePath;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use Throwable;

/**
 * Saves a shared secret for every holder at once, or not at all.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The one place where the row
 *   save, the copy fan-out, the partner shares and the stages meet; each is
 *   its own service and the coupling is the orchestration itself.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
class ShareSaveService {
	use TTransactional;

	/**
	 * The fields a chunk is checked against: a share-save always changes a value field.
	 *
	 * @var string[]
	 */
	private const CHUNK_FIELDS = ['key'];

	/**
	 * Constructor.
	 *
	 * @param SecretService $secrets The row save
	 * @param SecretMapper $secretMapper The version compare-and-set
	 * @param ShareSyncService $sync The copy writes
	 * @param FederatedShareService $federatedShares The partner share writes
	 * @param FederatedNotificationDelivery $delivery Partner notifications
	 * @param ShareSaveHistory $history The restore and team-write audit
	 * @param ShareSaveScope $scope The rows to write
	 * @param ShareSaveStages $stages Staged chunks
	 * @param IDBConnection $db The transaction
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretService $secrets,
		private SecretMapper $secretMapper,
		private ShareSyncService $sync,
		private FederatedShareService $federatedShares,
		private FederatedNotificationDelivery $delivery,
		private ShareSaveHistory $history,
		private ShareSaveScope $scope,
		private ShareSaveStages $stages,
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Add a chunk to a stage. Nothing but the stage is written.
	 *
	 * @param string $id The caller's row
	 * @param ShareSaveRequest $request A request with `stage.final` false
	 * @param string $userId The caller
	 *
	 * @return array{stageId:string,expiresAt:string,staged:array{copies:int,federated:int}}
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function stage(string $id, ShareSaveRequest $request, string $userId): array {
		[$path, $entries, $expiresAt, $chunks] = $this->open(id: $id, request: $request, userId: $userId, fields: self::CHUNK_FIELDS);

		$entries->assertNoDuplicates();
		$this->scope->assertWithinReach(path: $path, entries: $entries, chunks: $chunks + 1, userId: $userId);

		$added = $this->stages->add(
			stageId: $request->stageId,
			userId: $userId,
			secretId: $path->row->getId(),
			base: $request->baseUpdatedAt,
			sourceBase: $request->sourceBaseUpdatedAt,
			entries: $request->entries,
			expiresAt: $expiresAt,
		);

		return [
			'stageId' => $added['stageId'],
			'expiresAt' => $added['expiresAt']->format(DATE_ATOM),
			'staged' => ['copies' => $entries->copyCount(), 'federated' => $entries->federatedCount()],
		];
	}//end stage()

	/**
	 * Save the row, every copy and every partner share in one transaction,
	 * then tell the partner instances.
	 *
	 * @param string $id The caller's row
	 * @param ShareSaveRequest $request A one-call request or a stage's final call
	 * @param string $userId The caller
	 *
	 * @return array<string,mixed> The 200 body
	 *
	 * @throws StaleWriteException When `{id}` changed since the base
	 * @throws ShareSaveRefusal A refusal with its code; nothing was written
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function save(string $id, ShareSaveRequest $request, string $userId): array {
		$fields = array_keys((array)$request->patch);
		[$path, $entries] = $this->open(id: $id, request: $request, userId: $userId, fields: $fields);

		$version = null;
		if ($request->restoredFrom !== null) {
			$version = $this->history->versionToRestore(versionId: $request->restoredFrom, row: $path->row, userId: $userId);
		}

		$request->assertConsistent(entries: $entries);
		$this->scope->assertComplete(path: $path, entries: $entries, userId: $userId);

		$savedAt = $this->savedAt(path: $path, request: $request);
		$written = $this->dropStageWhenStale(
			request: $request,
			step: fn (): array => $this->atomic(
				fn: fn (): array => $this->write(
					path: $path,
					request: $request,
					entries: $entries,
					userId: $userId,
					savedAt: $savedAt,
					version: $version
				),
				db: $this->db
			)
		);

		$notified = $this->notify(rows: $written['federatedRows']);

		return [
			'secret' => $written['secret']->jsonSerialize(),
			'sourceUpdatedAt' => $savedAt->format(DATE_ATOM),
			'copies' => ['written' => $written['copies'], 'skipped' => count($entries->skipped)],
			'federated' => [
				'written' => count($written['federatedRows']),
				'suspended' => count($entries->suspend),
				'notified' => $notified,
				'queued' => count($written['federatedRows']) - $notified,
				'ownerOnly' => $written['ownerOnly'],
			],
		];
	}//end save()

	/**
	 * Load and check the caller's row, its path, the stage and the base
	 * versions; nothing is written but a stale stage being dropped.
	 *
	 * @param string $id The caller's row
	 * @param ShareSaveRequest $request The request
	 * @param string $userId The caller
	 * @param string[] $fields The fields the save changes
	 *
	 * @return array{0:ShareSavePath,1:ShareSaveEntries,2:DateTime|null,3:int} The path, the staged
	 *   entries followed by the request's, the stage's end and its chunk count
	 */
	private function open(string $id, ShareSaveRequest $request, string $userId, array $fields): array {
		$path = $this->scope->resolve(
			row: $this->secrets->loadForUpdate(id: $id, fields: $fields, userId: $userId),
			userId: $userId
		);

		if ($path->isCopyEditor() === true) {
			if ($request->entries->federatedCount() > 0) {
				throw new ShareSaveRefusal(
					error: 'federated_owner_only',
					message: 'Only the owner writes partner shares'
				);
			}

			if ($request->sourceBaseUpdatedAt === null) {
				throw new ShareSaveRefusal(
					error: 'source_base_required',
					message: 'sourceBaseUpdatedAt is required when saving through a copy'
				);
			}
		}

		$entries = $request->entries;
		$expiresAt = null;
		$chunks = 0;
		if ($request->stageId !== null) {
			$staged = $this->stages->load(
				stageId: $request->stageId,
				userId: $userId,
				secretId: $path->row->getId(),
				base: $request->baseUpdatedAt,
				sourceBase: $request->sourceBaseUpdatedAt,
			);
			$entries = $staged['entries']->merge($request->entries);
			$expiresAt = $staged['expiresAt'];
			$chunks = $staged['chunks'];
		}

		$this->dropStageWhenStale(
			request: $request,
			step: function () use ($path, $request): void {
				if ($this->movedOn(row: $path->row, base: $request->baseUpdatedAt) === true) {
					throw new StaleWriteException(current: $path->row);
				}

				if ($path->isCopyEditor() === true
					&& $this->movedOn(row: $path->source, base: $request->sourceBaseUpdatedAt) === true
				) {
					throw $this->staleSource(current: $path->source);
				}
			}
		);

		return [$path, $entries, $expiresAt, $chunks];
	}//end open()

	/**
	 * The transaction body: lock both versions, check the write grade and the
	 * lists again under the lock, write every copy, every partner share and
	 * the row itself.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveRequest $request The request
	 * @param ShareSaveEntries $entries The lists, staged ones included
	 * @param string $userId The caller
	 * @param DateTime $savedAt The version every written row gets
	 * @param SecretVersion|null $version The version a restore comes from
	 *
	 * @return array{secret:Secret,copies:int,federatedRows:list<FederatedShare>,ownerOnly:bool}
	 */
	private function write(
		ShareSavePath $path,
		ShareSaveRequest $request,
		ShareSaveEntries $entries,
		string $userId,
		DateTime $savedAt,
		?SecretVersion $version,
	): array {
		$this->lockVersions(path: $path, request: $request, savedAt: $savedAt);
		$this->scope->assertStillWriter(path: $path, userId: $userId);
		$required = $this->scope->assertComplete(path: $path, entries: $entries, userId: $userId);

		$copies = $this->sync->writeCopyBlobs(
			source: $path->source,
			updates: $entries->copies,
			allowedIds: array_fill_keys(array_keys($required['copies']), true),
			savedAt: $savedAt,
		);
		$federatedRows = $this->writePartners(
			path: $path,
			entries: $entries,
			required: $required['federated'],
			userId: $userId,
			savedAt: $savedAt
		);

		// The row goes last, through the same path as PUT /secrets/{id}, so its
		// snapshot, policy checks and audit are the ones every edit gets.
		$secret = $this->secrets->update(
			id: $path->row->getId(),
			data: (array)$request->patch,
			userId: $userId,
			savedAt: $savedAt
		);

		if ($version !== null) {
			$this->history->recordRestore(secret: $secret, version: $version, userId: $userId);
		}

		$this->history->recordTeamWrite(path: $path, userId: $userId);
		if ($request->stageId !== null) {
			$this->stages->drop(stageId: $request->stageId);
		}

		return [
			'secret' => $secret,
			'copies' => $copies,
			'federatedRows' => $federatedRows,
			'ownerOnly' => $required['ownerOnly'],
		];
	}//end write()

	/**
	 * Write and suspend the partner shares. A share revoked between the
	 * completeness check and this write is not overwritten: the lists are
	 * checked again, which now finds them incomplete.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveEntries $entries The lists
	 * @param array<string,FederatedShare> $required The active partner shares, by id
	 * @param string $userId The caller
	 * @param DateTime $savedAt The version every written row gets
	 *
	 * @return list<FederatedShare> The written shares
	 *
	 * @throws NotFoundException When a share went away and the lists still look complete
	 */
	private function writePartners(
		ShareSavePath $path,
		ShareSaveEntries $entries,
		array $required,
		string $userId,
		DateTime $savedAt,
	): array {
		$written = [];
		try {
			foreach ($entries->federated as $share) {
				$written[] = $this->federatedShares->writeCiphertext(
					row: $required[$share['shareId']],
					certFingerprint: $share['certFingerprint'],
					ciphertext: ['key' => $share['key'], 'login' => $share['login'], 'additionalFields' => $share['additionalFields']],
					savedAt: $savedAt,
				);
			}

			foreach ($entries->suspend as $suspend) {
				$this->federatedShares->suspend(shareId: $suspend['shareId'], userId: $userId, reason: $suspend['reason']);
			}
		} catch (NotFoundException $gone) {
			$this->scope->assertComplete(path: $path, entries: $entries, userId: $userId);
			throw $gone;
		}

		return $written;
	}//end writePartners()

	/**
	 * Move the source and the row to `$savedAt` if they are still at their
	 * base versions. The source goes first on both paths, so an owner save and
	 * a copy save of the same secret wait for each other instead of deadlocking.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveRequest $request The request
	 * @param DateTime $savedAt The new version
	 *
	 * @return void
	 *
	 * @throws StaleWriteException|ShareSaveRefusal When a version moved on
	 */
	private function lockVersions(ShareSavePath $path, ShareSaveRequest $request, DateTime $savedAt): void {
		$sourceBase = $request->sourceBaseUpdatedAt;
		if ($path->isCopyEditor() === true && $sourceBase !== null) {
			$sourceId = $path->source->getId();
			if ($this->secretMapper->advanceVersion(id: $sourceId, base: $sourceBase, savedAt: $savedAt) === 0) {
				throw $this->staleSource(current: $this->reload(id: $sourceId));
			}
		}

		$rowId = $path->row->getId();
		if ($this->secretMapper->advanceVersion(id: $rowId, base: $request->baseUpdatedAt, savedAt: $savedAt) === 0) {
			throw new StaleWriteException(current: $this->reload(id: $rowId));
		}
	}//end lockVersions()

	/**
	 * Whether a row is no longer at the version an edit was made from.
	 *
	 * @param Secret $row The row as loaded
	 * @param DateTime|null $base The base version
	 *
	 * @return bool
	 */
	private function movedOn(Secret $row, ?DateTime $base): bool {
		return $row->getUpdatedAt()?->getTimestamp() !== $base?->getTimestamp();
	}//end movedOn()

	/**
	 * The `stale_source` refusal, with the source's current version.
	 *
	 * @param Secret $current The source as it is now
	 *
	 * @return ShareSaveRefusal
	 */
	private function staleSource(Secret $current): ShareSaveRefusal {
		return new ShareSaveRefusal(
			error: 'stale_source',
			message: 'The source changed since the edit was made',
			status: Http::STATUS_CONFLICT,
			details: ['sourceUpdatedAt' => $current->getUpdatedAt()?->format(DATE_ATOM)],
		);
	}//end staleSource()

	/**
	 * Run a step; when it finds a version moved on, drop the stage before
	 * the refusal leaves, because its ciphertext was made from that version.
	 *
	 * @param ShareSaveRequest $request The request
	 * @param callable():T $step The step
	 *
	 * @return T
	 *
	 * @template T
	 */
	private function dropStageWhenStale(ShareSaveRequest $request, callable $step): mixed {
		try {
			return $step();
		} catch (StaleWriteException|ShareSaveRefusal $refusal) {
			$stale = ($refusal instanceof StaleWriteException) || $refusal->getError() === 'stale_source';
			if ($stale === true && $request->stageId !== null) {
				$this->stages->drop(stageId: $request->stageId);
			}

			throw $refusal;
		}
	}//end dropStageWhenStale()

	/**
	 * The version every row of the save gets: now, but always after both
	 * bases, because the column and the stale check count whole seconds.
	 *
	 * @param ShareSavePath $path The path
	 * @param ShareSaveRequest $request The request
	 *
	 * @return DateTime
	 */
	private function savedAt(ShareSavePath $path, ShareSaveRequest $request): DateTime {
		$floor = max(time(), $request->baseUpdatedAt->getTimestamp() + 1);
		if ($path->isCopyEditor() === true && $request->sourceBaseUpdatedAt !== null) {
			$floor = max($floor, $request->sourceBaseUpdatedAt->getTimestamp() + 1);
		}

		return new DateTime('@'.$floor);
	}//end savedAt()

	/**
	 * A row read again inside the transaction.
	 *
	 * @param string $id The secret ID
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When it was deleted meanwhile
	 */
	private function reload(string $id): Secret {
		try {
			return $this->secretMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret not found');
		}
	}//end reload()

	/**
	 * Tell each partner instance to pull again. A failure leaves the
	 * notification queued on the row for the retry job.
	 *
	 * @param list<FederatedShare> $rows The written partner shares
	 *
	 * @return int How many were delivered now
	 */
	private function notify(array $rows): int {
		$notified = 0;
		foreach ($rows as $row) {
			try {
				if ($this->delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED) === true) {
					$notified++;
				}
			} catch (Throwable) {
				// Committed and queued on the row: the retry job sends it.
			}
		}

		return $notified;
	}//end notify()
}//end class
