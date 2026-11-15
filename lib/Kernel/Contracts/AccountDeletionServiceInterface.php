<?php

/**
 * Contract of AccountDeletionService
 *
 * The public surface of AccountDeletionService that other components call, so they depend
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

use OCA\Keepiq\Service\DeletionReport;

/**
 * Contract of AccountDeletionService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccountDeletionServiceInterface {

	/**
	 * Delete all Keepiq data for a user, running the ordered cascade and
	 * dispatching AccountDataDeletedEvent on completion.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $trigger The trigger ('in-app' | 'user-deleted')
	 *
	 * @return DeletionReport The per-entity counts
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function deleteAllFor(string $userId, string $trigger = 'user-deleted'): DeletionReport;
}//end interface
