<?php

/**
 * Contract of LinkShareService
 *
 * The public surface of LinkShareService that other components call, so they depend
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
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Service\NewLinkShare;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Contract of LinkShareService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LinkShareServiceInterface {

	/**
	 * Confirm a successful client-side decryption (Phase 2).
	 *
	 * The use was already counted when the snapshot was served; a confirm
	 * only resets the attempts, so a recipient who decrypted is not pushed
	 * towards the attempt cap by later uses.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare The link share
	 *
	 * @throws RuntimeException When the token is invalid or the share is gone
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function confirmAccess(string $token): LinkShare;

	/**
	 * Create a link share for a secret the given user may re-share.
	 *
	 * A public link widens the audience beyond what the owner chose, so only
	 * the secret's owner, or a recipient whose share permits re-sharing (an
	 * active delegate, who holds share management rights), may create one
	 * (keepiq#214). Anyone else gets the same DoesNotExistException as a missing
	 * secret (from ShareAuthorizationService::assertMayReshare()), so the
	 * check does not reveal which secrets exist. The controller
	 * resolves the user's active encryption suite ID.
	 * Ownership of the resulting link share is recorded in created_by, which
	 * is the sole authority used by delete()/listBySecret() — this keeps the
	 * feature self-contained and IDOR-safe.
	 *
	 * @param NewLinkShare $link The secret, snapshot, salt, suite, limit, expiry and creator
	 *
	 * @return LinkShare
	 *
	 * @throws InvalidArgumentException When validation fails
	 * @throws DoesNotExistException When the user may not re-share the secret
	 * @throws RuntimeException A ForbiddenException when the secret is a read-only copy from another organisation,
	 *                          or a ShareScanPendingException (409) when the share row naming the secret, or the
	 *                          caller's copy of it, waits for the share quarantine scan
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
	 */
	public function create(NewLinkShare $link): LinkShare;

	/**
	 * Delete (revoke) a link share, validating ownership.
	 *
	 * @param string $id The link share ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the link share does not exist
	 * @throws InvalidArgumentException When the requester is not the owner
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function delete(string $id, string $userId): void;

	/**
	 * List link shares for a secret, scoped to the requesting owner.
	 *
	 * Only returns link shares the requesting user created (IDOR-safe).
	 *
	 * @param string $secretId The secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return LinkShare[]
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-multiple-concurrent-link-shares
	 */
	public function listBySecret(string $secretId, string $userId): array;

	/**
	 * Serve the encrypted snapshot for a token (Phase 1), counting the serve
	 * before the snapshot leaves the server.
	 *
	 * Every serve consumes one use and one attempt, atomically and only
	 * while uses and attempts remain, so the usage limit and the attempt cap
	 * hold whether or not the client ever confirms. The share is deleted as
	 * soon as the serve that reaches either one has been read.
	 *
	 * @param string $token The access token
	 *
	 * @return array<string, mixed> The public payload
	 *
	 * @throws RuntimeException When the token is invalid, expired, used up or out of attempts
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
	 * @spec openspec/specs/link-sharing/spec.md#requirement-auto-deletion
	 */
	public function serve(string $token): array;
}//end interface
