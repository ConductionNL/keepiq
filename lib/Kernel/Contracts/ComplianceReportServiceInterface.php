<?php

/**
 * Contract of ComplianceReportService
 *
 * The public surface of ComplianceReportService that other components call, so they depend
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

use OCA\Keepiq\Db\ComplianceReport;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of ComplianceReportService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceReportServiceInterface {

	/**
	 * The warm metrics cache (computed on demand when cold).
	 *
	 * @return array{computedAt:string|null, metrics:array<string,mixed>}
	 *
	 * @spec openspec/specs/compliance-reporting/spec.md#requirement-org-level-metadata-only-compliance-report
	 */
	public function cachedMetrics(): array;

	/**
	 * Generate + persist an immutable snapshot (§2.5).
	 *
	 * @param string $adminUid The generating admin
	 *
	 * @return ComplianceReport
	 *
	 * @spec openspec/changes/compliance-reporting/specs/compliance-reporting/spec.md
	 */
	public function generate(string $adminUid): ComplianceReport;

	/**
	 * One snapshot by id.
	 *
	 * @param string $id The report UUID
	 *
	 * @return ComplianceReport
	 *
	 * @throws DoesNotExistException When no row matches
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function getReport(string $id): ComplianceReport;

	/**
	 * List snapshots, newest first.
	 *
	 * @return ComplianceReport[]
	 *
	 * @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
	 */
	public function listReports(): array;

	/**
	 * Record an export beacon (§4.3).
	 *
	 * @param string $adminUid The exporting admin
	 * @param string $reportId The exported report
	 * @param string $format The export format (csv|pdf)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
	 */
	public function recordExport(string $adminUid, string $reportId, string $format): void;

	/**
	 * Recompute the warm metrics cache (daily job, §3.1).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-reporting/spec.md#requirement-org-level-metadata-only-compliance-report
	 */
	public function refreshMetricsCache(): void;
}//end interface
