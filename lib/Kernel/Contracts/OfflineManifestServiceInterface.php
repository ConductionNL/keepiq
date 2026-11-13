<?php

/**
 * Contract of OfflineManifestService
 *
 * The public surface of OfflineManifestService that other components call, so they depend
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

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of OfflineManifestService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OfflineManifestServiceInterface {

	/**
	 * Build the consolidated offline snapshot for one user.
	 *
	 * Every read is keyed by `('user', $userId)` so the snapshot can only ever
	 * contain rows the caller already owns — there is no cross-owner path
	 * through this method.
	 *
	 * @param string $userId The owning Nextcloud user ID
	 *
	 * @return array<string,mixed> The manifest payload
	 *
	 * @throws DoesNotExistException When the user has no active encryption
	 *                               suite — the caller maps this to a 404,
	 *                               because a snapshot without the suite blob
	 *                               cannot be decrypted offline and is worse
	 *                               than no snapshot at all.
	 *
	 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
	 * @spec openspec/specs/vault-policies/spec.md#requirement-vault-unlock-requires-nextcloud-two-factor-login
	 * @spec openspec/changes/sharing-atomic-share-save/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
	 */
	public function buildForUser(string $userId): array;
}//end interface
