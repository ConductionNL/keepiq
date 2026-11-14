<?php

/**
 * Contract of DelegationService
 *
 * The public surface of DelegationService that other components call, so they depend
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
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Exception\ShareScanPendingException;

/**
 * Contract of DelegationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DelegationServiceInterface {

	/**
	 * Whether $userId may use the admin handover path at all.
	 *
	 * Exposed here so the UI can decide whether to OFFER the takeover; the
	 * rule itself has a single home in DelegationAuthorizer, so the button
	 * and the enforcement can never drift apart.
	 *
	 * @param string $userId The candidate admin user ID
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.4
	 */
	public function canHandover(string $userId): bool;

	/**
	 * Create a temporary delegation by ADMIN POWER GRAB
	 * (FEATURES.md V1 §17.1, ownership-delegation spec.md).
	 *
	 * `initiatedBy` MUST hold the People admin area AND already
	 * hold a share of the Secret. The delegation is created with
	 * `delegated_to = initiatedBy` so the admin's own copy is promoted.
	 * Owner consent is NOT required, but the admin must already be a
	 * recipient — the path widens *who* can act, not what can be acted on.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $delegatedTo The user receiving delegation rights; MUST
	 *                            equal $initiatedBy on this path
	 * @param string $initiatedBy The vault admin initiating the handover
	 *
	 * @return SecretDelegation
	 *
	 * @throws InvalidArgumentException When the Secret does not exist, the
	 *                                  caller is not a vault admin,
	 *                                  $delegatedTo is not the initiator,
	 *                                  the initiator is already the owner,
	 *                                  or the initiator holds no
	 *                                  pre-existing share of the Secret.
	 * @throws ShareScanPendingException When the share row the handover sits on waits for the share quarantine scan
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function createAdminHandover(
		string $secretId,
		string $delegatedTo,
		string $initiatedBy,
	): SecretDelegation;

	/**
	 * Create a temporary delegation by OWNER SELF-DELEGATION
	 * (FEATURES.md V1 §17.1, ownership-delegation spec.md).
	 *
	 * `initiatedBy` MUST be `secret.owner_id`. The owner hands share/revoke
	 * authority to `delegatedTo`, who MUST already hold a share of the
	 * Secret (when ShareTargetMapper is wired); otherwise the call is
	 * rejected, because a delegation promotes an *existing* recipient copy
	 * to co-owner status.
	 *
	 * The admin power-grab path lives in createAdminHandover() — the two
	 * are separate entry points rather than one flag-selected method,
	 * because they are different authorization decisions.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $delegatedTo The user receiving delegation rights
	 * @param string $initiatedBy The user initiating the delegation
	 *
	 * @return SecretDelegation
	 *
	 * @throws InvalidArgumentException When the Secret does not exist,
	 *                                  the caller is not the owner, the
	 *                                  delegate is the owner themselves,
	 *                                  or the delegate holds no
	 *                                  pre-existing share of the Secret.
	 * @throws ShareScanPendingException When the share row the delegation sits on waits for the share quarantine scan
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.2
	 */
	public function createDelegation(
		string $secretId,
		string $delegatedTo,
		string $initiatedBy,
	): SecretDelegation;

	/**
	 * Return all delegations for the given Secret — visible only to the
	 * Secret owner.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $ownerId The requesting owner
	 *
	 * @return SecretDelegation[]
	 *
	 * @throws InvalidArgumentException When the Secret does not exist or
	 *                                  the caller is not the owner.
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.1
	 */
	public function getDelegationsForSecret(string $secretId, string $ownerId): array;

	/**
	 * Promote all TEMPORARY delegations for the given original owner to
	 * permanent. Called by the EncryptionSuiteRevoked listener when the
	 * original owner loses access for good and any delegate currently
	 * holding the share needs to become the de-facto owner.
	 *
	 * @param string $originalOwnerId The original owner user ID
	 *
	 * @return int Rows affected.
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.4
	 */
	public function makePermanent(string $originalOwnerId): int;

	/**
	 * Reclaim — the original owner revokes all TEMPORARY delegations they
	 * created for the given Secret. Permanent delegations are immutable
	 * and are NOT touched.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $ownerId The original owner reclaiming
	 *
	 * @return int The number of delegations removed.
	 *
	 * @throws InvalidArgumentException When the Secret does not exist or
	 *                                  the caller is not the original owner.
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.3
	 */
	public function reclaimDelegation(string $secretId, string $ownerId): int;

	/**
	 * Revoke all TEMPORARY delegations the given original owner created.
	 * Permanent delegations are immutable and are NOT touched.
	 *
	 * Called by the EncryptionSuiteRevoked listener when an administrator
	 * force-revokes the owner's suite as COMPROMISED (keepiq#817, ADR-005):
	 * a temporary delegation is the cheapest foothold to create from a
	 * stolen session, so a compromise cuts it instead of promoting it.
	 * Each removed row is audited as a reclaim by the revoking actor.
	 *
	 * @param string $originalOwnerId The original owner user ID
	 * @param string $revokedBy       The actor who revoked the suite
	 *
	 * @return int The number of delegations removed.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-permanent-transfer-on-suite-revocation
	 */
	public function revokeTemporary(string $originalOwnerId, string $revokedBy): int;
}//end interface
