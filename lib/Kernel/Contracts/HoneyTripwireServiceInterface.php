<?php

/**
 * Contract of HoneyTripwireService
 *
 * The public surface of HoneyTripwireService that other components call, so they depend
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

use OCA\Keepiq\Service\HoneyAccess;

/**
 * Contract of HoneyTripwireService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HoneyTripwireServiceInterface {

	/**
	 * Raise (or collapse into) an alert for an access to a possibly-
	 * flagged secret (§2.2). Fail-soft by contract: any failure is
	 * logged and swallowed — the observed access is already served.
	 *
	 * Dedup (D5): repeats by the same (accessor, channel) within the
	 * configurable window update the existing alert instead of paging
	 * again. A snoozed accessor never pages but IS still audited — the
	 * forensic trail stays complete.
	 *
	 * @param HoneyAccess $access The observed access
	 *
	 * @return bool Whether the secret was honey-flagged (a tripwire hit)
	 *
	 * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md
	 */
	public function raiseAlert(HoneyAccess $access): bool;
}//end interface
