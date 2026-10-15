<?php

/**
 * Keepiq ShareSaveStages
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSave
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

namespace OCA\Keepiq\Service\ShareSave;

use DateInterval;
use DateTime;
use OCA\Keepiq\Db\ShareSaveStage;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCP\AppFramework\Http;
use Ramsey\Uuid\Uuid;

/**
 * The chunks of a share-save too large for one request. A stage belongs to
 * one user, one row and one base version, and lives 30 minutes.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */
class ShareSaveStages {
	/**
	 * How long a stage lives after its first chunk.
	 *
	 * @var string
	 */
	public const LIFETIME = 'PT30M';

	/**
	 * Constructor.
	 *
	 * @param ShareSaveStageMapper $mapper The chunks
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(private ShareSaveStageMapper $mapper) {
	}//end __construct()

	/**
	 * Every entry staged so far, refused unless the stage is live and was
	 * made by this user for this row and these base versions.
	 *
	 * @param string $stageId The stage
	 * @param string $userId The caller
	 * @param string $secretId The row, `{id}`
	 * @param DateTime $base The row's base version
	 * @param DateTime|null $sourceBase The source's base version on the copy-editor path
	 *
	 * @return array{entries:ShareSaveEntries,expiresAt:DateTime,chunks:int}
	 *
	 * @throws ShareSaveRefusal `stage_unknown` or `stage_mismatch`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function load(string $stageId, string $userId, string $secretId, DateTime $base, ?DateTime $sourceBase): array {
		$chunks = $this->mapper->findLiveByStage(stageId: $stageId, now: new DateTime());
		if ($chunks === []) {
			throw new ShareSaveRefusal(
				error: 'stage_unknown',
				message: 'The stage does not exist or expired',
				status: Http::STATUS_NOT_FOUND
			);
		}

		$entries = new ShareSaveEntries();
		foreach ($chunks as $chunk) {
			if ($chunk->getUserId() !== $userId || $chunk->getSecretId() !== $secretId
				|| $chunk->getBaseUpdatedAt()?->getTimestamp() !== $base->getTimestamp()
				|| $chunk->getSourceBaseUpdatedAt()?->getTimestamp() !== $sourceBase?->getTimestamp()
			) {
				throw new ShareSaveRefusal(
					error: 'stage_mismatch',
					message: 'The stage was made for another user, row or version',
					status: Http::STATUS_CONFLICT
				);
			}

			$lists = json_decode($chunk->getEntries(), true, 16, JSON_THROW_ON_ERROR);
			$entries = $entries->merge(
				new ShareSaveEntries(
					copies: $lists['copies'],
					skipped: $lists['skipped'],
					federated: $lists['federated'],
					suspend: $lists['suspend'],
				)
			);
		}

		return [
			'entries' => $entries,
			'expiresAt' => $chunks[0]->getExpiresAt() ?? new DateTime(),
			'chunks' => count($chunks),
		];
	}//end load()

	/**
	 * Store one chunk. Without a stage id it opens a new stage, which
	 * replaces the user's earlier stage for the same row.
	 *
	 * @param string|null $stageId The stage to add to, or null for a new one
	 * @param string $userId The caller
	 * @param string $secretId The row, `{id}`
	 * @param DateTime $base The row's base version
	 * @param DateTime|null $sourceBase The source's base version on the copy-editor path
	 * @param ShareSaveEntries $entries The chunk's lists
	 * @param DateTime|null $expiresAt The stage's end, for a stage that exists
	 *
	 * @return array{stageId:string,expiresAt:DateTime}
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function add(
		?string $stageId,
		string $userId,
		string $secretId,
		DateTime $base,
		?DateTime $sourceBase,
		ShareSaveEntries $entries,
		?DateTime $expiresAt,
	): array {
		$now = new DateTime();
		if ($stageId === null || $expiresAt === null) {
			$this->mapper->deleteByUserAndSecret(userId: $userId, secretId: $secretId);
			$stageId = Uuid::uuid4()->toString();
			$expiresAt = (clone $now)->add(new DateInterval(self::LIFETIME));
		}

		$chunk = new ShareSaveStage();
		$chunk->setId(Uuid::uuid4()->toString());
		$chunk->setStageId($stageId);
		$chunk->setUserId($userId);
		$chunk->setSecretId($secretId);
		$chunk->setBaseUpdatedAt(clone $base);
		$chunk->setSourceBaseUpdatedAt($sourceBase);
		$chunk->setEntries($entries->toJson());
		$chunk->setCopyCount($entries->copyCount());
		$chunk->setFederatedCount($entries->federatedCount());
		$chunk->setExpiresAt(clone $expiresAt);
		$chunk->setCreatedAt($now);
		$this->mapper->insert(entity: $chunk);

		return ['stageId' => $stageId, 'expiresAt' => $expiresAt];
	}//end add()

	/**
	 * Drop a stage the caller was checked to own.
	 *
	 * @param string $stageId The stage
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function drop(string $stageId): void {
		$this->mapper->deleteByStage(stageId: $stageId);
	}//end drop()

	/**
	 * Drop every stage past its 30 minutes.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int The number of chunks removed
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function dropExpired(DateTime $now): int {
		return $this->mapper->deleteExpired(now: $now);
	}//end dropExpired()
}//end class
