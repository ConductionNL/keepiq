<?php

/**
 * Contract of ShareRequestService
 *
 * The public surface of ShareRequestService that other components call, so they depend
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
 * Contract of ShareRequestService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareRequestServiceInterface {

	/**
	 * Approve a share request. The browser produces the recipient's
	 * RSA-encrypted copy and registers it first, then calls this to confirm:
	 * once the share exists the requester is told it was approved.
	 *
	 * @param array<string,mixed> $params The notification parameters {sourceSecretId, requesterId, targetUserId}
	 * @param string $ownerId The approver (must be the secret owner)
	 *
	 * @return array{sourceSecretId:string,requesterId:string,targetUserId:string,shared:bool}
	 *
	 * @throws InvalidArgumentException When the approver is not the owner
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.2
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	public function approveShareRequest(array $params, string $ownerId): array;

	/**
	 * Deny a share request — fires a 'share_request_result' notification
	 * at the requester so they know the answer.
	 *
	 * @param array<string,mixed> $params The original notification parameters
	 * @param string $ownerId The denier (must be the secret owner)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the denier is not the owner
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.3
	 */
	public function denyShareRequest(array $params, string $ownerId): void;

	/**
	 * Submit a share request — fires a 'share_request' notification at
	 * the secret's owner; the notification payload carries the data the
	 * approval path will need to register the share (register-batch, which
	 * mints the recipient copy server-side).
	 *
	 * @param string $sourceSecretId The owner's source Secret ID
	 * @param string $targetUserId The user the requester wants the secret shared with
	 * @param string $requesterId The user submitting the request (must hold a share)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the requester does not hold a share or the secret is missing
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.1
	 */
	public function submitShareRequest(
		string $sourceSecretId,
		string $targetUserId,
		string $requesterId,
	): void;
}//end interface
