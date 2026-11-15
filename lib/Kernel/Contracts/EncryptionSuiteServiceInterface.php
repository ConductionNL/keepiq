<?php

/**
 * Contract of EncryptionSuiteService
 *
 * The public surface of EncryptionSuiteService that other components call, so they depend
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

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Exception\ReinstateRefusedException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of EncryptionSuiteService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EncryptionSuiteServiceInterface {

	/**
	 * Create a successor suite for a compromise recovery.
	 *
	 * The one flow allowed a second active suite: the old one stays active and
	 * readable so the browser can decrypt what it is migrating, while the successor
	 * takes new writes.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $publicKeyPem PEM-encoded public key
	 * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
	 *
	 * @return EncryptionSuite
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function createSuccessorSuite(
		string $ownerType,
		string $ownerId,
		string $publicKeyPem,
		string $encryptedPrivateKey,
	): EncryptionSuite;

	/**
	 * Create an EncryptionSuite for a user or application.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $publicKeyPem PEM-encoded public key
	 * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
	 * @return EncryptionSuite
	 *
	 * Refuses when the owner already has an active suite — see the provisioning
	 * service, which throws. A compromise recovery uses createSuccessorSuite().
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function createSuite(
		string $ownerType,
		string $ownerId,
		string $publicKeyPem,
		string $encryptedPrivateKey,
	): EncryptionSuite;

	/**
	 * Get the active EncryptionSuite for an owner.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function getActiveSuite(string $ownerType, string $ownerId): EncryptionSuite;

	/**
	 * Get an EncryptionSuite by ID.
	 *
	 * @param string $id The suite ID
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function getSuite(string $id): EncryptionSuite;

	/**
	 * Get all EncryptionSuites for an owner.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return EncryptionSuite[]
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function getSuitesByOwner(string $ownerType, string $ownerId): array;

	/**
	 * Record a refused revocation in the audit trail (keepiq#870).
	 *
	 * Successful revokes were audited and refusals only reached the HTTP
	 * response, so an attack on the containment path was invisible to the
	 * SIEM. The reason code is a fixed machine token chosen by the caller
	 * (`migration_in_progress`, `invalid_argument`, `forbidden`,
	 * `empty_reason`, `migration_end_failed`), never an exception message, so nothing a request
	 * supplied ends up in the trail.
	 *
	 * @param string $suiteId         The suite the revoke targeted
	 * @param string $actorId         Who asked for the revoke
	 * @param string $reasonCode      Why it was refused, as a fixed token
	 * @param bool   $markCompromised Whether a compromise revoke was asked for
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $markCompromised is recorded
	 *   data about the refused request, not a mode switch for this method.
	 *
	 * @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	public function recordRevokeRefused(
		string $suiteId,
		string $actorId,
		string $reasonCode,
		bool $markCompromised = false,
	): void;

	/**
	 * Reinstate a revoked EncryptionSuite. Re-signs the public key with the active intermediate.
	 *
	 * Refused for a suite revoked as compromised: reinstating it re-opens every
	 * secret under a key the administrator declared to be in an attacker's
	 * hands. ADR-005 keeps that decision off the suite row, so it is read from
	 * the suite's last SUITE_REVOKED audit entry; when no such entry can be
	 * found the reinstate is refused too, because the revocation cannot be
	 * shown to be a harmless one. Also refused when the owner already has
	 * another active suite, which would leave them with two (keepiq#865).
	 *
	 * @param string $id The suite ID
	 * @param string $reinstatedBy The user who reinstated the suite
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 * @throws ReinstateRefusedException From SuiteReinstateGuard::assertReinstatable()
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-revoked-as-compromised-cannot-be-reinstated
	 */
	public function reinstateSuite(string $id, string $reinstatedBy): EncryptionSuite;

	/**
	 * Revoke an EncryptionSuite.
	 *
	 * @param string $id The suite ID
	 * @param string $reason The reason for revocation
	 * @param string $revokedBy The user who revoked the suite
	 * @param bool $markCompromised Treat the suite's secrets as compromised (admin force-revoke)
	 * @param int $emergencyContactsDestroyed Usable emergency contacts cleared by the revoke (audit only)
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $markCompromised is the
	 *   administrator's explicit, transient compromise decision (ADR-005),
	 *   threaded onto the dispatched event and the audit metadata; the owner
	 *   path leaves it at its default and stays behaviourally unchanged.
	 * @SuppressWarnings(PHPMD.LongVariable) $emergencyContactsDestroyed is the
	 *   audit-metadata / response contract name (ADR-005 D5) it threads through;
	 *   the descriptive name is deliberate and matches the surfaced field.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	public function revokeSuite(
		string $id,
		string $reason,
		string $revokedBy,
		bool $markCompromised = false,
		int $emergencyContactsDestroyed = 0,
	): EncryptionSuite;

	/**
	 * Persist changes to an EncryptionSuite.
	 *
	 * @param EncryptionSuite $suite The suite to update
	 *
	 * @return EncryptionSuite
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	public function updateSuite(EncryptionSuite $suite): EncryptionSuite;
}//end interface
