<?php

/**
 * Contract of RecoveryEnrolmentService
 *
 * The public surface of RecoveryEnrolmentService that other components call, so they depend
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
use OCA\Keepiq\Db\RecoveryEnrolment;
use OCA\Keepiq\Exception\ForbiddenException;

/**
 * Contract of RecoveryEnrolmentService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecoveryEnrolmentServiceInterface {

	/**
	 * Delete the enrolments of a suite (rotation done or suite revoked, D7).
	 *
	 * @param string $suiteId The suite
	 *
	 * @return int The number deleted
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function deleteForSuite(string $suiteId): int;

	/**
	 * Enrol, replacing any earlier enrolment of the user.
	 *
	 * @param string $userId        The user
	 * @param string $recoveryKeyId The key the envelope is wrapped to (must be active)
	 * @param string $envelope      The hybrid envelope of the user's private key
	 *
	 * @return RecoveryEnrolment
	 *
	 * @throws ForbiddenException       When recovery is off
	 * @throws InvalidArgumentException When the key is not the active one or the envelope is empty
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function enrol(string $userId, string $recoveryKeyId, string $envelope): RecoveryEnrolment;

	/**
	 * Whether a suite has an enrolment (the admin's revocation warning).
	 *
	 * @param string $suiteId The suite
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-force-revocation-warns-about-enrolled-users
	 */
	public function isSuiteEnrolled(string $suiteId): bool;

	/**
	 * What the user's browser needs: the policy, whether the user is enrolled
	 * to the current key and suite, and the active key to enrol with.
	 *
	 * @param string $userId The user
	 *
	 * @return array{policy:string,enrolled:bool,current:bool,key:array<string,mixed>|null}
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function status(string $userId): array;

	/**
	 * Withdraw, refused under the `required` policy.
	 *
	 * @param string $userId The user
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the policy requires enrolment
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function withdraw(string $userId): void;
}//end interface
