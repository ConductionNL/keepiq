<?php

/**
 * Contract of RecoveryRequestService
 *
 * The public surface of RecoveryRequestService that other components call, so they depend
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\RecoveryRequest;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of RecoveryRequestService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecoveryRequestServiceInterface {

	/**
	 * Count one officer's approval; move the request to approved at the
	 * threshold. The vault-key proof is checked before this runs.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return string The request status afterwards
	 *
	 * @throws ForbiddenException When the caller is not an officer or approves their own request
	 * @throws NotFoundException  When the request is not open
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function approve(string $id, string $officerUid): string;

	/**
	 * Mark the user's request fulfilled after their browser re-wrapped the
	 * suite under a new master password, and delete the sealed result.
	 *
	 * @param string $id     The request
	 * @param string $userId The user
	 *
	 * @return string The officer who handled it
	 *
	 * @throws NotFoundException For every caller and state it is not for
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function complete(string $id, string $userId): string;

	/**
	 * File a request from the lock screen with the browser's one-time key.
	 * Any earlier open request of the user ends.
	 *
	 * @param string $userId    The user who forgot their master password
	 * @param string $publicKey The one-time X25519 public key (base64, raw 32 bytes)
	 * @param string $purpose   `password` or `device`
	 *
	 * @return RecoveryRequest
	 *
	 * @throws ForbiddenException       When the user is not enrolled or recovery is off
	 * @throws InvalidArgumentException When the key is malformed
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	public function create(string $userId, string $publicKey, string $purpose = 'password'): RecoveryRequest;

	/**
	 * Decline: any officer may end a request.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the caller is not an officer
	 * @throws NotFoundException  When the request is not open
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function decline(string $id, string $officerUid): void;

	/**
	 * End the open requests of a revoked suite (D7).
	 *
	 * @param string $suiteId The suite
	 *
	 * @return int The number ended
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function endForSuite(string $suiteId): int;

	/**
	 * Expire open requests past their 72 hours and drop any sealed result.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int The number expired
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	public function expireLapsed(DateTime $now): int;

	/**
	 * The open requests an officer may act on, with the approval count.
	 * Never the officer's own request, never key material.
	 *
	 * @param string $officerUid The officer
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ForbiddenException When the caller is not an officer
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function forOfficer(string $officerUid): array;

	/**
	 * The user's own latest request, with the sealed result once it exists.
	 *
	 * @param string $userId The user
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function forUser(string $userId): ?array;

	/**
	 * The handoff material, only for an officer who approved an approved
	 * request: the user's enrolment envelope and the officer's own copy of
	 * the recovery key it is wrapped to.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return array{requestPublicKey:string,envelope:string,wrappedRecoveryKey:string,recoveryKeyId:string}
	 *
	 * @throws NotFoundException For every caller and state it is not for
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function handoff(string $id, string $officerUid): array;

	/**
	 * Store the private key an approving officer sealed to the request key.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 * @param string $sealed     The HPKE-sealed private key
	 *
	 * @return void
	 *
	 * @throws NotFoundException        For every caller and state it is not for
	 * @throws InvalidArgumentException When the sealed result is empty
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function postSealed(string $id, string $officerUid, string $sealed): void;
}//end interface
