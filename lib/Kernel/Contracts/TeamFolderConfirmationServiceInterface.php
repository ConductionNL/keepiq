<?php

/**
 * Contract of TeamFolderConfirmationService
 *
 * The public surface of TeamFolderConfirmationService that other components call, so they depend
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

/**
 * Contract of TeamFolderConfirmationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeamFolderConfirmationServiceInterface {

	/**
	 * Whether the administrator switched automatic confirmation on.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-pending-confirmations-are-served-to-authorised-confirmers-only
	 */
	public function isEnabled(): bool;

	/**
	 * The team folders where the user may confirm and members still wait.
	 *
	 * Owned folders list every missing pair. For a folder the user is a member
	 * of, only pairs whose source the user holds with `write` grade and a
	 * current copy of are listed, each with the id of that copy. Empty when
	 * the switch is off.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<int,array{teamFolderId:string,role:string,missing:array<int,array<string,string>>,recipients:array<int,array{userId:string,certificate:string}>}>
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-pending-confirmations-are-served-to-authorised-confirmers-only
	 */
	public function pendingConfirmations(string $userId): array;

	/**
	 * Register fan-out rows from the owner, a manager or a `write`-grade confirmer.
	 *
	 * The owner and a manager (effective grade `manage`) take the plain
	 * fan-out, which checks the manage grade and the subtree itself
	 * (sharing-team-folder-manager-role D2). Anyone else needs the switch on,
	 * and every row is checked before anything is stored; rows that fail a
	 * check are skipped as `not_eligible`, so the pair stays pending.
	 *
	 * @param string $teamFolderId The team folder
	 * @param array<int,array<string,mixed>> $rows The browser-encrypted rows
	 * @param string $userId The caller
	 *
	 * @return array{created:int,rows:array<int,array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}>,skipped:list<array{sourceSecretId:string,targetUserId:string,reason:string}>}
	 *
	 * @throws InvalidArgumentException When the folder is missing or the caller may not confirm
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
	 */
	public function registerShares(string $teamFolderId, array $rows, string $userId): array;
}//end interface
