<?php

/**
 * Keepiq Direct Share Row
 *
 * One row of a bulk direct-share request and the report entry it produces.
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

use OCA\Keepiq\Db\Secret;

/**
 * The sharing owner and one {sourceSecretId, targetUserId, encryptedKey, ...}
 * row of a bulk direct-share request.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
 */
final class DirectShareRow {
	/**
	 * Constructor for DirectShareRow.
	 *
	 * @param string $userId The sharing owner
	 * @param string $sourceSecretId The source secret the row names
	 * @param string $targetUserId The recipient
	 * @param string $encryptedKey Recipient-encrypted key blob
	 * @param array<string,mixed> $row The original row (optional blobs and restriction)
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $sourceSecretId,
		public readonly string $targetUserId,
		public readonly string $encryptedKey,
		public readonly array $row,
	) {
	}//end __construct()

	/**
	 * Whether the row lacks its source, recipient or key.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function isIncomplete(): bool {
		return $this->sourceSecretId === '' || $this->targetUserId === '' || $this->encryptedKey === '';
	}//end isIncomplete()

	/**
	 * The report entry for a row that was not shared.
	 *
	 * @param string $status Why the row was not shared
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string}
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function outcome(string $status): array {
		return [
			'sourceSecretId' => $this->sourceSecretId,
			'targetUserId' => $this->targetUserId,
			'status' => $status,
		];
	}//end outcome()

	/**
	 * The report entry for a row whose copy was created.
	 *
	 * @param Secret $copy The recipient's copy
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId:string}
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function created(Secret $copy): array {
		return [
			'sourceSecretId' => $this->sourceSecretId,
			'targetUserId' => $this->targetUserId,
			'status' => 'created',
			'recipientSecretId' => $copy->getId(),
		];
	}//end created()

	/**
	 * The outcome of a row encrypted from an older version: `stale_source` with the current version, so the
	 * browser decrypts the source again and sends the row once more.
	 *
	 * @param string|null $current The source's current version, null when it is gone
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string,sourceUpdatedAt:string|null}
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function stale(?string $current): array {
		return [
			'sourceSecretId'  => $this->sourceSecretId,
			'targetUserId'    => $this->targetUserId,
			'status'          => 'stale_source',
			'sourceUpdatedAt' => $current,
		];
	}//end stale()

	/**
	 * The TOTP and passkeys ciphertexts the row carries for the copy.
	 *
	 * @return array{totp:mixed,passkeys:mixed}
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function carriedFields(): array {
		return ['totp' => ($this->row['encryptedTotp'] ?? null), 'passkeys' => ($this->row['encryptedPasskeys'] ?? null)];
	}//end carriedFields()
}//end class
