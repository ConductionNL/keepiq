<?php

/**
 * Contract of RecoveryKeyService
 *
 * The public surface of RecoveryKeyService that other components call, so they depend
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
use OCA\Keepiq\Db\RecoveryKey;
use OCA\Keepiq\Db\RecoveryOfficer;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of RecoveryKeyService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecoveryKeyServiceInterface {

	/**
	 * The active recovery key, or null when none exists yet.
	 *
	 * @return RecoveryKey|null
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	public function activeKey(): ?RecoveryKey;

	/**
	 * Create a new recovery key from an officer's browser: certify its public
	 * half and store one wrapped copy per named officer. An existing active
	 * key becomes `retiring`, so enrolled users re-enrol at their next unlock.
	 *
	 * @param string               $officerUid   The officer creating it
	 * @param string               $publicKeyPem The recovery public key
	 * @param array<string,string> $copies       Officer uid => wrapped private key
	 *
	 * @return RecoveryKey
	 *
	 * @throws ForbiddenException       When the caller is not an officer
	 * @throws InvalidArgumentException When a copy is missing or one is for a non-officer
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	public function createKey(string $officerUid, string $publicKeyPem, array $copies): RecoveryKey;

	/**
	 * An officer's own copy of a recovery key (the active one by default).
	 *
	 * @param string      $officerUid    The officer
	 * @param string|null $recoveryKeyId The key, or null for the active one
	 *
	 * @return RecoveryOfficer
	 *
	 * @throws NotFoundException When the officer holds no copy
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	public function ownCopy(string $officerUid, ?string $recoveryKeyId = null): RecoveryOfficer;

	/**
	 * What any user may see of the active key: the certificate, its
	 * fingerprint and the instance CA chain to check it against.
	 *
	 * @return array{id:string,certificate:string,fingerprint:string,caChain:string[]}|null
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function publicInfo(): ?array;

	/**
	 * Replace an officer's own copy after their suite rotated (D7).
	 *
	 * @param string $officerUid    The officer
	 * @param string $recoveryKeyId The key
	 * @param string $wrapped       The copy wrapped to the officer's new suite
	 *
	 * @return void
	 *
	 * @throws NotFoundException        When the officer holds no copy of that key
	 * @throws InvalidArgumentException When the officer has no active suite
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function replaceOwnCopy(string $officerUid, string $recoveryKeyId, string $wrapped): void;

	/**
	 * Retire a recovery key: no new enrolments go to it.
	 *
	 * @param string $recoveryKeyId The key
	 * @param string $adminUid      The administrator
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the key does not exist
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function retire(string $recoveryKeyId, string $adminUid): void;
}//end interface
