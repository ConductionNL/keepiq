<?php

/**
 * Contract of AdminSuiteRevocationService
 *
 * The public surface of AdminSuiteRevocationService that other components call, so they depend
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

/**
 * Contract of AdminSuiteRevocationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdminSuiteRevocationServiceInterface {

	/**
	 * The `error` of a compromise revoke whose migration end failed.
	 *
	 * @var string
	 */
	public const MIGRATION_END_FAILED = 'migration_end_failed';

	/**
	 * Force-revoke a suite that is not compromised.
	 *
	 * @param string $suiteId  The suite to revoke
	 * @param string $reason   The administrator's reason
	 * @param string $adminUid The acting administrator
	 *
	 * @return array<string,mixed> The response body
	 *
	 * @throws \OCA\Keepiq\Exception\SuiteMigrationInProgressException When the suite is in an in-progress migration
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	public function revoke(string $suiteId, string $reason, string $adminUid): array;

	/**
	 * Force-revoke a suite as compromised, and contain what its key reached.
	 *
	 * The blast radius is collected from BOTH ends of an open migration before
	 * either is revoked, because each revoke's cascade deletes the ShareTargets
	 * the lookup reads (keepiq#864). Containment then stamps and warns, revokes
	 * the user's link shares and passkeys (LinkShareService::deleteMadeBy via
	 * MigrationService, keepiq#858) and ends their sessions (keepiq#860). Its
	 * failures are counted and returned as `cascadeIncomplete`, so the
	 * administrator is not told containment ran when part of it did not
	 * (keepiq#863), and audited as `suite.compromise_contained` (keepiq#1189).
	 *
	 * Once the named suite is revoked, a failure to revoke the other end or to
	 * end the migration does not stop its containment: it is counted as a
	 * failed step and returned as `error: migration_end_failed`, not as a
	 * refused revoke. The migration stays open, so a retry finds it. The retry
	 * only finishes the migration when the audit trail shows the containment
	 * completed; otherwise it runs everything again (keepiq#1189).
	 *
	 * @param string $suiteId  The suite to revoke
	 * @param string $reason   The administrator's reason
	 * @param string $adminUid The acting administrator
	 *
	 * @return array<string,mixed> The response body
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function revokeAsCompromise(string $suiteId, string $reason, string $adminUid): array;
}//end interface
