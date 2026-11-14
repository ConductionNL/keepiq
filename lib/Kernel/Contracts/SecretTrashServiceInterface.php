<?php

/**
 * Contract of SecretTrashService
 *
 * The public surface of SecretTrashService that other components call, so they depend
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ShareScanPendingException;

/**
 * Contract of SecretTrashService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretTrashServiceInterface {

	/**
	 * Default days a trashed secret is kept (design D4).
	 *
	 * @var int
	 */
	public const RETENTION_DEFAULT = 30;

	/**
	 * Smallest retention an administrator may set.
	 *
	 * @var int
	 */
	public const RETENTION_MIN = 1;

	/**
	 * Largest retention an administrator may set.
	 *
	 * @var int
	 */
	public const RETENTION_MAX = 365;

	/**
	 * Archive a secret: it leaves the vault list, search, autofill and the
	 * health report, and keeps its shares (D5).
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the secret is in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function archive(string $id, string $userId): Secret;

	/**
	 * Delete a trashed secret for good, with the full delete cascade.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the secret is not in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function purge(string $id, string $userId): void;

	/**
	 * Take a secret out of the trash. It comes back unshared. A read-only
	 * copy from another organisation takes back the share it came from,
	 * when the owner still has it (sharing-federated-recipients, decision of
	 * 4 Oct 2026); `federatedShare` says what became of it, null for any
	 * other secret.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return array{secret: Secret, federatedShare: string|null}
	 *
	 * @throws InvalidArgumentException When the secret is not in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-restores-his-copy
	 */
	public function restore(string $id, string $userId): array;

	/**
	 * Move a secret to the trash and end everybody else's access to it now.
	 *
	 * Link shares, open secret requests, user shares, group shares and
	 * delegations are revoked; the ciphertext, attachments, versions and
	 * rotation flags stay, so a restore gives the secret back whole (D2).
	 * A secret already in the trash is left as it is.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 * @param string|null $baseUpdatedAt The version an offline delete was made from
	 *
	 * @return Secret
	 *
	 * @throws \OCA\Keepiq\Exception\StaleWriteException When the secret changed since that version
	 * @throws ShareScanPendingException When the pending share quarantine scan cannot judge a share row of the secret
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function trash(string $id, string $userId, ?string $baseUpdatedAt=null): Secret;

	/**
	 * Bring an archived secret back into the vault.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return Secret
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function unarchive(string $id, string $userId): Secret;
}//end interface
