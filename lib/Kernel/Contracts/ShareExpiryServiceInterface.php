<?php

/**
 * Contract of ShareExpiryService
 *
 * The public surface of ShareExpiryService that other components call, so they depend
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

/**
 * Contract of ShareExpiryService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareExpiryServiceInterface {

	/**
	 * Remove every expired grant and every copy left expired, and notify.
	 *
	 * @param DateTime $now The cut-off
	 *
	 * @return int The number of copies whose access ended in this run
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	public function expire(DateTime $now): int;

	/**
	 * Warn every holder whose access ends in (from, to].
	 *
	 * @param DateTime $from Exclusive lower bound
	 * @param DateTime $to   Inclusive upper bound
	 *
	 * @return int The number of warnings sent
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
	 */
	public function warnEnding(DateTime $from, DateTime $to): int;
}//end interface
