<?php

/**
 * Contract of SecretVersionService
 *
 * The public surface of SecretVersionService that other components call, so they depend
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Exception\StaleWriteException;

/**
 * Contract of SecretVersionService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretVersionServiceInterface {

	/**
	 * One version WITH its ciphertext blobs for client-side decrypt.
	 * Refused when the version's wrapping suite is revoked/compromised —
	 * matching the head-read posture.
	 *
	 * @param string $versionId The version UUID
	 * @param string $userId The caller
	 *
	 * @return SecretVersion
	 *
	 * @throws \InvalidArgumentException On not found / not owned / suite blocked
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	public function getVersion(string $versionId, string $userId): SecretVersion;

	/**
	 * List a secret's versions (metadata only), newest first — owner-only;
	 * an inaccessible secret yields an empty list (no existence oracle).
	 *
	 * @param string $secretId The secret UUID
	 * @param string $userId The caller
	 *
	 * @return SecretVersion[]
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	public function list(string $secretId, string $userId): array;

	/**
	 * Restore a version on the head row alone, after snapshotting the head.
	 * Copies are not written here: a restore that must reach them is a
	 * share-save with `restoredFrom`. The head is moved off the version just
	 * read (the base, when one is given) by a compare-and-set inside the
	 * transaction, as a share-save does, so a save that commits after the
	 * read is refused here rather than overwritten unsnapshotted.
	 *
	 * @param string $versionId The version UUID
	 * @param string $userId The caller (must own the secret)
	 * @param string|null $baseUpdatedAt The head version the caller restores over; null skips the comparison with it, not the lock
	 *
	 * @return Secret The updated head
	 *
	 * @throws \InvalidArgumentException On not found / not owned / suite blocked / a malformed base
	 * @throws \OCA\Keepiq\Exception\ForbiddenException When the head is a copy its holder may only view, or a read-only copy from another organisation
	 * @throws \OCA\Keepiq\Exception\NotFoundException When the head is a copy whose source cannot be resolved
	 * @throws StaleWriteException When the head moved on from `$baseUpdatedAt`, or from the version read
	 * @throws ShareScanPendingException When the head is a copy whose share row waits for the share quarantine scan
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function restore(string $versionId, string $userId, ?string $baseUpdatedAt = null): Secret;
}//end interface
