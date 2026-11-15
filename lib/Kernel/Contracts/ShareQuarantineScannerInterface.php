<?php

/**
 * Contract of ShareQuarantineScanner
 *
 * The public surface of ShareQuarantineScanner that other components call, so they depend
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

use OCP\Migration\IOutput;

/**
 * Contract of ShareQuarantineScanner.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareQuarantineScannerInterface {

	/**
	 * Clear the record of a held-back resolve once every copy was resolved,
	 * unless a resolve was held back again since it was read.
	 *
	 * @param string $record The record heldBackResolve() returned
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function clearHeldBackResolve(string $record): void;

	/**
	 * The record of a held-back restriction resolve, or null when there is none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function heldBackResolve(): ?string;

	/**
	 * Whether a full pass has completed in the current generation.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function isComplete(): bool;

	/**
	 * Set the scan pending again in a new generation, so the background job
	 * runs a new pass and a pass already running cannot record completion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function reopen(): void;

	/**
	 * Run one full pass and record it as completed. A failure anywhere in that
	 * is logged at error level and leaves the scan pending, so the background
	 * job runs the pass again; it never reaches the caller, so `occ upgrade`
	 * or a restore cannot fail on it. A pass that ends after a reopen() leaves
	 * the scan pending too: it may have missed the rows the restore wrote.
	 *
	 * @param IOutput|null $output The migration output, when run from one
	 *
	 * @return bool Whether the pass completed
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function run(?IOutput $output = null): bool;
}//end interface
