<?php

/**
 * Contract of EmergencyEnvelopeInvalidationService
 *
 * The public surface of EmergencyEnvelopeInvalidationService that other components call, so they depend
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
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of EmergencyEnvelopeInvalidationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmergencyEnvelopeInvalidationServiceInterface {

	/**
	 * Clear a grantor's recovery envelopes after their suite is REVOKED — the
	 * envelopes hold a now-void key and are deleted outright.
	 *
	 * @param string $grantorSuiteId The revoked suite ID
	 *
	 * @return int The number of relationships cleared
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function clearForGrantorRevocation(string $grantorSuiteId): int;

	/**
	 * Count the grantor's usable (non-invalidated) emergency contacts on a suite.
	 *
	 * A revocation about to DELETE these envelopes uses this to refuse silently
	 * destroying a still-working break-glass path: the count (never the
	 * identities, which stay grantor-private) is surfaced so the administrator
	 * can decide with the loss in view.
	 *
	 * @param string $grantorSuiteId The grantor suite about to be revoked
	 *
	 * @return int The number of usable emergency contacts bound to that suite
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function countUsableForGrantorSuite(string $grantorSuiteId): int;

	/**
	 * Invalidate envelopes encrypted to a grantee whose suite was revoked — the
	 * grantee can no longer open them, so they are marked invalid.
	 *
	 * @param string $granteeSuiteId The revoked grantee suite ID
	 *
	 * @return int The number of relationships invalidated
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function invalidateForGranteeRevocation(string $granteeSuiteId): int;

	/**
	 * Invalidate the contacts a ROTATION (compromise recovery) left on the old
	 * suite. Their envelopes hold the stale private key, so they are marked
	 * invalid.
	 *
	 * A contact with a break-glass requested or approved gets `{reason}_in_flight`,
	 * which the view warns about: that is what a contact planted with a stolen
	 * session looks like. Every other contact gets `{reason}`. The server cannot
	 * see which contacts the owner ticked, so it does not try to tell an
	 * unticked contact from an unreachable one; the view offers Re-establish for
	 * neither, and only the recovery form prompts (#804 review).
	 *
	 * @param string $grantorSuiteId The rotated (old) suite ID
	 * @param string $reason The invalidation reason tag
	 *
	 * @return int The number of relationships invalidated
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function invalidateForGrantorRotation(string $grantorSuiteId, string $reason = 'grantor_rotation'): int;

	/**
	 * Migrate one recovery envelope onto the grantor's new suite during a
	 * compromise-recovery rotation.
	 *
	 * The browser has already minted a fresh envelope escrowing the grantor's
	 * NEW private key, sealed to the grantee's current certificate. This re-points
	 * the contact to the new suite and stores that envelope, keeping the contact
	 * `granted`. Unlike the other migrated stores the grantor cannot decrypt what
	 * it just wrote (only the grantee can), so the envelope is shape-checked, not
	 * round-tripped, and the declared grantee suite is asserted to be the
	 * grantee's CURRENT active suite — an envelope sealed to a stale grantee key
	 * would be unopenable.
	 *
	 * Ownership and old-suite binding are enforced exactly as the other migration
	 * writes: the caller (MigrationController) has already established that the
	 * migration belongs to $ownerId, and this insists the contact does too and is
	 * still on $oldSuiteId before touching it.
	 *
	 * @param string $ownerId The migration owner (the contact's grantor)
	 * @param string $oldSuiteId The migration's old suite (the contact must be on it)
	 * @param string $newSuiteId The migration's new suite (the contact is re-pointed to it)
	 * @param string $contactId The emergency-contact ID to re-point
	 * @param string $recoveryEnvelope The fresh envelope escrowing the new private key
	 * @param string $sealedSuiteId The grantee suite the client sealed to
	 *
	 * @return EmergencyContact The re-pointed contact
	 *
	 * @throws NotFoundException When the contact does not exist
	 * @throws ForbiddenException When the contact is not this migration's to touch
	 * @throws InvalidArgumentException When the envelope is malformed or misaddressed
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function reEnvelopeForRotation(
		string $ownerId,
		string $oldSuiteId,
		string $newSuiteId,
		string $contactId,
		string $recoveryEnvelope,
		string $sealedSuiteId,
	): EmergencyContact;
}//end interface
