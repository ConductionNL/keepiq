<?php

/**
 * Unit tests for the ComplianceReportController metrics and export-beacon
 * endpoints (GET /api/v1/compliance/metrics,
 * POST /api/v1/compliance/reports/{id}/exported).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\ComplianceReportController;
use OCA\Keepiq\Service\ComplianceReportService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `complianceReport#metrics` and `complianceReport#exported`.
 *
 * Both endpoints carry #[NoAdminRequired], so the admin gate is entirely the
 * method body's own `adminUid()` check (compliance-reporting §7.3). Two
 * distinct non-admin shapes therefore have to be proven separately: no session
 * at all, and a logged-in ordinary user. In both the service must not be
 * reached — a 403 that still ran the report logic would still have leaked it.
 *
 * The export beacon additionally validates the format allowlist and must audit
 * with the ADMIN's uid plus the report id from the URL.
 *
 */
class ComplianceReportControllerTest extends TestCase {

	/**
	 * The mocked compliance report service.
	 *
	 * @var ComplianceReportService&MockObject
	 */
	private ComplianceReportService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The mocked group manager backing the admin gate.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(ComplianceReportService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build the controller under test with its collaborators mocked.
	 *
	 * @param string|null $userId The session uid, or null for an anonymous caller.
	 * @param bool $isAdmin Whether that uid is in the admin group.
	 *
	 * @return ComplianceReportController The controller under test.
	 */
	private function controller(?string $userId = 'admin', bool $isAdmin = true): ComplianceReportController {
		if ($userId === null) {
			$this->userSession->method('getUser')->willReturn(null);
			$this->groupManager->expects($this->never())->method('isAdmin');
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$this->userSession->method('getUser')->willReturn($user);
			$this->groupManager->method('isAdmin')->with($userId)->willReturn($isAdmin);
		}

		return new ComplianceReportController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			userSession: $this->userSession,
			groupManager: $this->groupManager
		);
	}//end controller()

	/**
	 * The happy path for the metrics card: an admin receives the service's warm
	 * metrics payload verbatim.
	 *
	 * @return void
	 */
	public function testMetricsReturnsTheWarmCacheForAnAdmin(): void {
		$metrics = [
			'secretsTotal' => 42,
			'weakPasswords' => 3,
			'applicationsActive' => 7,
			'refreshedAt' => '2026-08-09T10:00:00+00:00',
		];

		$this->service->expects($this->once())
			->method('cachedMetrics')
			->willReturn($metrics);

		$response = $this->controller(userId: 'admin', isAdmin: true)->metrics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($metrics, $response->getData());
	}//end testMetricsReturnsTheWarmCacheForAnAdmin()

	/**
	 * An anonymous caller gets 403 and the metrics are never computed.
	 *
	 * @return void
	 */
	public function testMetricsForbidsAnAnonymousCallerWithoutComputingAnything(): void {
		$this->service->expects($this->never())->method('cachedMetrics');

		$response = $this->controller(userId: null)->metrics();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Compliance reporting is admin-only'], $response->getData());
	}//end testMetricsForbidsAnAnonymousCallerWithoutComputingAnything()

	/**
	 * A logged-in but non-admin user is a distinct branch from "no session" and
	 * must be refused just as hard.
	 *
	 * @return void
	 */
	public function testMetricsForbidsALoggedInNonAdminWithoutComputingAnything(): void {
		$this->service->expects($this->never())->method('cachedMetrics');

		$response = $this->controller(userId: 'bob', isAdmin: false)->metrics();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Compliance reporting is admin-only'], $response->getData());
	}//end testMetricsForbidsALoggedInNonAdminWithoutComputingAnything()

	/**
	 * The happy path for the export beacon: the audit record carries the
	 * ADMIN's uid, the report id from the URL and the requested format.
	 *
	 * @return void
	 */
	public function testExportedRecordsTheAuditEventWithTheAdminUidReportIdAndFormat(): void {
		$reportId = '5c9e6679-7425-40de-944b-e07fc1f90ae7';

		// The ITEM: the beacon audits the acting admin, not the report's author.
		$this->service->expects($this->once())
			->method('recordExport')
			->with('admin', $reportId, 'csv');

		$response = $this->controller(userId: 'admin', isAdmin: true)->exported($reportId, 'csv');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['recorded' => true], $response->getData());
	}//end testExportedRecordsTheAuditEventWithTheAdminUidReportIdAndFormat()

	/**
	 * The second allowed format travels through unchanged too — the allowlist
	 * is not a synonym for "csv".
	 *
	 * @return void
	 */
	public function testExportedAcceptsThePdfFormatAndForwardsItUnchanged(): void {
		$reportId = '5c9e6679-7425-40de-944b-e07fc1f90ae7';

		$this->service->expects($this->once())
			->method('recordExport')
			->with('admin', $reportId, 'pdf');

		$response = $this->controller(userId: 'admin', isAdmin: true)->exported($reportId, 'pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['recorded' => true], $response->getData());
	}//end testExportedAcceptsThePdfFormatAndForwardsItUnchanged()

	/**
	 * A format outside the csv|pdf allowlist is a 400 and writes no audit
	 * record.
	 *
	 * @return void
	 */
	public function testExportedRejectsAFormatOutsideTheAllowlist(): void {
		$this->service->expects($this->never())->method('recordExport');

		$response = $this->controller(userId: 'admin', isAdmin: true)
			->exported('5c9e6679-7425-40de-944b-e07fc1f90ae7', 'xlsx');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'format must be csv or pdf'], $response->getData());
	}//end testExportedRejectsAFormatOutsideTheAllowlist()

	/**
	 * The format parameter defaults to the empty string, which is also outside
	 * the allowlist — an omitted format must not audit an empty export.
	 *
	 * @return void
	 */
	public function testExportedRejectsAnOmittedFormat(): void {
		$this->service->expects($this->never())->method('recordExport');

		$response = $this->controller(userId: 'admin', isAdmin: true)
			->exported('5c9e6679-7425-40de-944b-e07fc1f90ae7');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'format must be csv or pdf'], $response->getData());
	}//end testExportedRejectsAnOmittedFormat()

	/**
	 * An anonymous caller cannot write an audit record — the admin gate runs
	 * before the format check.
	 *
	 * @return void
	 */
	public function testExportedForbidsAnAnonymousCallerWithoutRecordingAnything(): void {
		$this->service->expects($this->never())->method('recordExport');

		$response = $this->controller(userId: null)
			->exported('5c9e6679-7425-40de-944b-e07fc1f90ae7', 'csv');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Compliance reporting is admin-only'], $response->getData());
	}//end testExportedForbidsAnAnonymousCallerWithoutRecordingAnything()

	/**
	 * A logged-in non-admin cannot forge a compliance-export audit entry.
	 *
	 * @return void
	 */
	public function testExportedForbidsALoggedInNonAdminWithoutRecordingAnything(): void {
		$this->service->expects($this->never())->method('recordExport');

		$response = $this->controller(userId: 'bob', isAdmin: false)
			->exported('5c9e6679-7425-40de-944b-e07fc1f90ae7', 'csv');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Compliance reporting is admin-only'], $response->getData());
	}//end testExportedForbidsALoggedInNonAdminWithoutRecordingAnything()

}//end class
