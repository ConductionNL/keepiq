<?php

/**
 * Contract of ShareSaveService
 *
 * The public surface of ShareSaveService that other components call, so they depend
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

use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;

/**
 * Contract of ShareSaveService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareSaveServiceInterface {

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
	public function save(string $id, ShareSaveRequest $request, string $userId): array;

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
	public function stage(string $id, ShareSaveRequest $request, string $userId): array;
}//end interface
