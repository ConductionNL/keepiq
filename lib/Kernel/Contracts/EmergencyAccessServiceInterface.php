<?php

/**
 * Contract of EmergencyAccessService
 *
 * The public surface of EmergencyAccessService that other components call, so they depend
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
use OCA\Keepiq\Service\EmergencyDesignation;

/**
 * Contract of EmergencyAccessService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmergencyAccessServiceInterface {

	/**
	 * Approve every elapsed pending request (the background-job entry point).
	 *
	 * @return int The number of relationships promoted to `approved`
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function approveElapsed(): int;

	/**
	 * Decline (veto) a pending break-glass request as the grantor. Releases
	 * nothing and returns the relationship to the `granted` state so the contact
	 * remains designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return EmergencyContact
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantor
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-grantor-decline-veto
	 */
	public function decline(string $grantorUserId, string $id): EmergencyContact;

	/**
	 * Designate (or re-establish) a grantee as an emergency contact.
	 *
	 * The recovery envelope is built entirely in the grantor's browser and
	 * supplied here as opaque ciphertext — the server stores it verbatim and
	 * never sees a usable key (design D1/D2).
	 *
	 * @param EmergencyDesignation $designation The grantor, grantee, wait period, level and envelope
	 *
	 * @return EmergencyContact
	 *
	 * @throws InvalidArgumentException When validation fails (bad level, wait
	 *                                  period, self-designation, or the grantee has no active suite)
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
	 */
	public function designate(EmergencyDesignation $designation): EmergencyContact;

	/**
	 * Fetch the recovery envelope as the grantee. Released ONLY when the request
	 * is `approved` and the caller is the named grantee — every other state or
	 * caller is refused identically (no oracle, design D4). Notifies the grantor
	 * on actual access.
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return string The grantee-encrypted recovery envelope ciphertext
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When not approved or the caller is not the grantee
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function fetchEnvelope(string $granteeUserId, string $id): string;

	/**
	 * Return the grantee's active-suite certificate so the grantor's browser
	 * can build the recovery envelope. Fails loudly when the grantee has no
	 * active suite (the envelope cannot be built — design D7).
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @return array{suiteId: string, certificate: string}
	 *
	 * @throws InvalidArgumentException When the grantee has no active suite
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function getGranteeCertificate(string $granteeUserId): array;

	/**
	 * List the relationships where the user is the grantee (incoming access).
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @return EmergencyContact[]
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 */
	public function listForGrantee(string $granteeUserId): array;

	/**
	 * List the emergency contacts a grantor has designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 *
	 * @return EmergencyContact[]
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function listForGrantor(string $grantorUserId): array;

	/**
	 * Initiate a break-glass request as the grantee. Starts the grantor's wait
	 * timer and notifies the grantor; releases no key material.
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return EmergencyContact
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantee or the state
	 *                            does not allow a request
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 */
	public function request(string $granteeUserId, string $id): EmergencyContact;

	/**
	 * Revoke an emergency contact: delete the recovery envelope and cancel any
	 * pending request. A revoked contact cannot break glass until re-designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantor
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
	 */
	public function revoke(string $grantorUserId, string $id): void;
}//end interface
