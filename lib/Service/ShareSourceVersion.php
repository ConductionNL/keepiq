<?php

/**
 * Keepiq Share Source Version
 *
 * The source version a share row says its ciphertext was encrypted from, and whether that is still the current
 * version of the row it was encrypted from, read under the source's row lock (keepiq#1255).
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
use OCA\Keepiq\Db\SecretVersionLock;

/**
 * Reads, locks and compares the `sourceUpdatedAt` of a register-batch or team-folder share row.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */
class ShareSourceVersion {
	/**
	 * Constructor for ShareSourceVersion.
	 *
	 * @param SecretVersionLock $locks The row locks
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(private SecretVersionLock $locks) {
	}//end __construct()

	/**
	 * The version a row names: null when it names none (an older client), '' when it names one that is not
	 * a moment.
	 *
	 * @param array<string,mixed> $row The submitted row
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function sent(array $row): ?string {
		$sent = ($row['sourceUpdatedAt'] ?? null);
		if ($sent === null) {
			return null;
		}

		if (is_string($sent) === false || strtotime($sent) === false) {
			return '';
		}

		return $sent;
	}//end sent()

	/**
	 * Take the source's row lock and read the version of the row a share was encrypted from: the source, or
	 * the caller's own copy of it, read after the source's lock, in the order a share-save takes them.
	 *
	 * @param string $sourceId The source the share hangs on
	 * @param string $readId The row the browser decrypted
	 *
	 * @return DateTime|null The version of the row it was encrypted from, null when gone
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function lockedVersion(string $sourceId, string $readId): ?DateTime {
		$current = $this->locks->lock(id: $sourceId);
		if ($readId !== $sourceId) {
			$current = $this->locks->lock(id: $readId);
		}

		return $current;
	}//end lockedVersion()

	/**
	 * Take the row locks of the named sources in id order, so two callers never wait on each other, and read
	 * each version.
	 *
	 * @param list<string> $sourceIds The sources
	 *
	 * @return array<string,DateTime|null> The version by source id, null for a source that is gone
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function lockAll(array $sourceIds): array {
		$sourceIds = array_values(array_unique($sourceIds));
		sort($sourceIds);
		$versions = [];
		foreach ($sourceIds as $sourceId) {
			$versions[$sourceId] = $this->locks->lock(id: $sourceId);
		}

		return $versions;
	}//end lockAll()

	/**
	 * Lock every allowed source a row names a version of, in id order.
	 *
	 * @param array<int,array<string,mixed>> $rows The submitted rows
	 * @param array<string,bool> $allowedIds The sources the caller may name, by id
	 *
	 * @return array<string,DateTime|null> The version by source id
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function lockNamed(array $rows, array $allowedIds): array {
		$ids = [];
		foreach ($rows as $row) {
			$sourceId = (string)($row['sourceSecretId'] ?? '');
			if ($this->sent(row: $row) !== null && isset($allowedIds[$sourceId]) === true) {
				$ids[] = $sourceId;
			}
		}

		return $this->lockAll(sourceIds: $ids);
	}//end lockNamed()

	/**
	 * Why a row may not go on: `invalid` for a version that is not a moment, `stale_source` with the current
	 * version for one its source no longer holds; null when it may go on or names no locked source.
	 *
	 * @param array<string,mixed> $row The submitted row
	 * @param array<string,DateTime|null> $versions The locked versions, from lockNamed()
	 *
	 * @return array{reason:string,extra:array<string,string|null>}|null
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#scenario-a-share-made-during-a-save-is-sent-again
	 */
	public function refusal(array $row, array $versions): ?array {
		$sent     = $this->sent(row: $row);
		$sourceId = (string)($row['sourceSecretId'] ?? '');
		if ($sent === '') {
			return ['reason' => 'invalid', 'extra' => []];
		}

		if ($sent === null || array_key_exists($sourceId, $versions) === false
			|| $this->isCurrent(sent: $sent, current: $versions[$sourceId]) === true
		) {
			return null;
		}

		return ['reason' => 'stale_source', 'extra' => ['sourceUpdatedAt' => $versions[$sourceId]?->format(DATE_ATOM)]];
	}//end refusal()

	/**
	 * Whether a sent version is the current one, compared in whole seconds as the column stores it.
	 *
	 * @param string $sent A version sent() accepted
	 * @param DateTime|null $current The version under the lock, null when the row is gone
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function isCurrent(string $sent, ?DateTime $current): bool {
		return $current !== null && $current->getTimestamp() === (new DateTime($sent))->getTimestamp();
	}//end isCurrent()
}//end class
