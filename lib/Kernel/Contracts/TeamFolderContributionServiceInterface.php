<?php

/**
 * Contract of TeamFolderContributionService
 *
 * The public surface of TeamFolderContributionService that other components call, so they depend
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
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of TeamFolderContributionService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeamFolderContributionServiceInterface {

	/**
	 * What a write-grade member's browser needs to encrypt a contribution:
	 * the owner's certificate and every eligible member's certificate.
	 * Public key material only.
	 *
	 * @param string $teamFolderId The team folder
	 * @param string $userId The contributing member
	 *
	 * @return array{teamFolderId:string,folderId:string,ownerCertificate:string,recipients:array<int,array{userId:string,certificate:string}>}
	 *
	 * @throws NotFoundException When the team folder is unknown
	 * @throws ForbiddenException When the caller holds no write grade there
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	public function context(string $teamFolderId, string $userId): array;

	/**
	 * The team folders the user may contribute to: covered by a membership
	 * row, not owned, with an effective `write` grade on the folder itself.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<int,array{teamFolderId:string,folderId:string,folderName:string}>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	public function contributable(string $userId): array;

	/**
	 * Store a member's new secret in a team folder.
	 *
	 * @param string $teamFolderId The team folder
	 * @param array<string,mixed> $data name, url, typeId, folderId, key, login,
	 *                                  additionalFields, totp, passkeys (owner ciphertext) and
	 *                                  copies (rows per member)
	 * @param string $userId The contributing member
	 *
	 * @return array{secret:Secret,copies:int,skipped:list<array{sourceSecretId:string,targetUserId:string,reason:string}>}
	 *   The member copies written, and those the fan-out skipped with the reason
	 *
	 * @throws NotFoundException When the team folder or target folder is unknown
	 * @throws ForbiddenException When the caller holds no write grade there
	 * @throws InvalidArgumentException On a missing name or owner ciphertext, or a member copy without
	 *   the `totp` or `passkeys` the owner ciphertext carries; nothing is stored
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
	 */
	public function contribute(string $teamFolderId, array $data, string $userId): array;
}//end interface
