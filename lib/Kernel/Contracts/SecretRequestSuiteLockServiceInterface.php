<?php

/**
 * Contract of SecretRequestSuiteLockService
 *
 * The public surface of SecretRequestSuiteLockService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of SecretRequestSuiteLockService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretRequestSuiteLockServiceInterface {

	/**
	 * Lock all pending requests bound to an EncryptionSuite. Invoked by
	 * the compromise-recovery flow when the recipient's keys are flagged.
	 *
	 * @param string $encryptionSuiteId The recipient's old EncryptionSuite ID
	 *
	 * @return int The number of rows affected.
	 *
	 * @throws InvalidArgumentException When the suite ID is blank.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-master-password-change-compromise-recovery
	 */
	public function lockByEncryptionSuiteId(string $encryptionSuiteId): int;

	/**
	 * Re-point locked requests at a new EncryptionSuite + reopen them.
	 *
	 * @param string $oldEncryptionSuiteId The old EncryptionSuite ID
	 * @param string $newEncryptionSuiteId The new EncryptionSuite ID
	 *
	 * @return int The number of rows affected.
	 *
	 * @throws InvalidArgumentException When either suite ID is blank.
	 * @throws RuntimeException When both suite IDs are the same.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	public function unlockAndUpdateSuite(string $oldEncryptionSuiteId, string $newEncryptionSuiteId): int;

	/**
	 * Unlock the requests locked on a suite, leaving them on that suite.
	 *
	 * The counterpart of lockByEncryptionSuiteId() for a migration that moved
	 * nothing onto the new suite. unlockAndUpdateSuite() cannot do this: it
	 * refuses an old and new suite that are the same (#809 review).
	 *
	 * @param string $encryptionSuiteId The suite the requests are locked on
	 *
	 * @return int The number of requests unlocked
	 *
	 * @throws InvalidArgumentException When the suite id is empty
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	public function unlockInPlace(string $encryptionSuiteId): int;
}//end interface
