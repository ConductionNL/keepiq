<?php

/**
 * Contract of ShareService
 *
 * The public surface of ShareService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareRestriction;

/**
 * Contract of ShareService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareServiceInterface {

	/**
	 * List all share targets for a given source secret.
	 *
	 * Only the owner or an active delegate sees the recipient list; for
	 * recipients and non-participants the return is an empty array (so
	 * UI can hide the section without raising a 403).
	 *
	 * @param string $sourceSecretId The source secret ID
	 * @param string $userId The requesting user ID
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#3.5
	 */
	public function listSharesForSecret(string $sourceSecretId, string $userId = ''): array;

	/**
	 * The active certificates of several prospective recipients.
	 *
	 * @param string[] $targetUserIds The prospective recipients
	 *
	 * @return array<string,string> PEM certificate, keyed by user ID
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
	 */
	public function recipientCertificates(array $targetUserIds): array;

	/**
	 * Register a batch of DIRECT user-to-user shares from client-encrypted
	 * blobs (bulk-actions §6.1/§7.1).
	 *
	 * @param string $userId The sharing owner
	 * @param array<int,array<string,mixed>> $shares Rows {sourceSecretId, targetUserId, encryptedKey, encryptedLogin?, encryptedAdditionalFields?,
	 *   encryptedTotp?, encryptedPasskeys?}
	 *
	 * @return array<int,array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}>
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function registerDirectShares(string $userId, array $shares): array;

	/**
	 * Revoke a single share target — deletes the recipient's encrypted
	 * Secret copy and the share-target row in one transaction.
	 *
	 * @param string $shareId The share-target row ID
	 * @param string $userId The Nextcloud user ID requesting the revoke
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the row does not exist or
	 *                                  the caller is not authorized.
	 * @throws \OCA\Keepiq\Exception\ConflictException When the row is quarantined, or the pending scan holds it back
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function revokeShare(string $shareId, string $userId): void;

	/**
	 * Push an updated encrypted blob to every recipient.
	 *
	 * @param string $secretId The source secret ID
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param string $expectedUpdatedAt The owner-side expected ISO timestamp
	 * @param string $userId The requesting user
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @throws InvalidArgumentException When validation or optimistic-lock check fails
	 * @throws ShareScanPendingException When the pending quarantine scan cannot judge the row of a copy the sync writes
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 */
	public function syncUpdate(
		string $secretId,
		array $updates,
		string $expectedUpdatedAt,
		string $userId,
	): int;

	/**
	 * Change the use-only flag and end date of a direct share (owner or
	 * delegate only), then recompute the recipient's copy. A share that
	 * came from a group share or a team folder is changed there instead.
	 * A delegate may not change a share made to themselves, nor any share
	 * while their own copy may not be shared onward, so a restricted grant
	 * can never be lifted into a full one (GHSA-m5p9-6pw7-pf3g).
	 *
	 * @param string $shareId The share-target row ID
	 * @param ShareRestriction $restriction The new use-only flag and end date
	 * @param string $userId The requesting user
	 *
	 * @return ShareTarget
	 *
	 * @throws InvalidArgumentException When not found, not authorized, not a direct share, or the delegate's own grant
	 * @throws \OCA\Keepiq\Exception\ForbiddenException When the delegate's copy is a read-only copy from another organisation
	 * @throws ConflictException When the pending quarantine scan holds the row back
	 * @throws ShareScanPendingException When the pending quarantine scan cannot judge the row, the caller's copy or a row it resolves
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.1
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function updateRestriction(string $shareId, ShareRestriction $restriction, string $userId): ShareTarget;

	/**
	 * The write context of a secret for the current user
	 * (folder-permission-grades §4).
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string|null, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist or the caller does not reach it
	 * @throws ShareScanPendingException When the caller's row has copies and the row naming it waits for the scan
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function writeContext(string $secretId, string $userId): array;
}//end interface
