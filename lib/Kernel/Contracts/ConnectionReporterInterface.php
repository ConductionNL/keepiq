<?php

/**
 * Contract of ConnectionReporter
 *
 * The public surface of ConnectionReporter that other components call, so they depend
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

use Throwable;

/**
 * Contract of ConnectionReporter.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionReporterInterface {

	/**
	 * After an admin save wrote `breach_check_enabled`: ask integriq to look again.
	 *
	 * No report follows. Integriq reads the `hibp` switch itself (rule 2b), and
	 * a lookup reports once a user checks a password.
	 *
	 * @return bool True when the refresh was sent.
	 *
	 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function breachCheckSaved(): bool;

	/**
	 * The HTTP status a failed call still carries, for {@see reportBreachLookup()}.
	 *
	 * Pure: reads, stores and sends nothing.
	 *
	 * @param Throwable $exception What the call threw.
	 *
	 * @return int|null The answer's HTTP status, or null when nothing answered.
	 *
	 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function httpStatusOf(Throwable $exception): ?int;

	/**
	 * Report what one range lookup to Have I Been Pwned met.
	 *
	 * @param int|null $httpStatus The upstream's HTTP status, or null when nothing answered.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function reportBreachLookup(?int $httpStatus): bool;
}//end interface
