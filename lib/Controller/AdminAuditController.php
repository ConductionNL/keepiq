<?php

/**
 * Keepiq admin API: audit and compliance
 *
 * Part of the versioned admin API under /api/v1/admin (admin-public-api).
 * The Audit area: audit events, compliance reports and SIEM sinks.
 * Each method is guarded by one admin area and calls the service the admin
 * screen calls; it holds no logic beyond parameter mapping.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ComplianceReportService;
use OCA\Keepiq\Service\Siem\SiemSinkRequest;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * `/api/v1/admin/audit`, `/compliance/reports` and `/siem/sinks`.
 */
class AdminAuditController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param AuditService $audit The audit query the admin screen uses
	 * @param ComplianceReportService $reports The compliance reports
	 * @param SiemService $siem The SIEM sinks
	 * @param IUserSession $userSession The acting administrator
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private AuditService $audit,
		private ComplianceReportService $reports,
		private SiemService $siem,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Audit events, filtered and paged like the admin screen.
	 *
	 * @param string|null $eventType Event type filter
	 * @param string|null $actor Actor filter
	 * @param string|null $objectType Object type filter
	 * @param string|null $objectId Object id filter
	 * @param string|null $from ISO 8601 lower bound
	 * @param string|null $to ISO 8601 upper bound
	 * @param int $page 1-based page
	 * @param int $limit Page size
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	public function events(
		?string $eventType = null,
		?string $actor = null,
		?string $objectType = null,
		?string $objectId = null,
		?string $from = null,
		?string $to = null,
		int $page = 1,
		int $limit = 50,
	): JSONResponse {
		$filters = [
			'eventType' => $eventType,
			'actor' => $actor,
			'objectType' => $objectType,
			'objectId' => $objectId,
			'from' => $from,
			'to' => $to,
		];

		return new JSONResponse(data: $this->audit->adminQuery($filters, $page, $limit));
	}//end events()

	/**
	 * The compliance reports, newest first.
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	public function reports(): JSONResponse {
		return new JSONResponse(
			data: array_map(
				static fn (object $report): array => [
					'id' => $report->getId(),
					'generatedBy' => $report->getGeneratedBy(),
					'generatedAt' => $report->getGeneratedAt()?->format('c'),
					'appVersion' => $report->getAppVersion(),
				],
				$this->reports->listReports()
			)
		);
	}//end reports()

	/**
	 * Generate a compliance report now.
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	#[UserRateLimit(limit: 10, period: 60)]
	public function generateReport(): JSONResponse {
		return new JSONResponse(
			data: $this->reports->generate(adminUid: $this->actor())->jsonSerialize(),
			statusCode: Http::STATUS_CREATED
		);
	}//end generateReport()

	/**
	 * One compliance report.
	 *
	 * @param string $id The report id
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	public function showReport(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->reports->getReport(id: $id)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Report not found'], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end showReport()

	/**
	 * The SIEM sinks. A sink's HMAC secret and connector credential never
	 * appear in its serialized form.
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	public function sinks(): JSONResponse {
		return new JSONResponse(
			data: array_map(static fn (object $sink): array => $sink->jsonSerialize(), $this->siem->listSinks())
		);
	}//end sinks()

	/**
	 * Create a SIEM sink; the body is the admin screen's.
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function createSink(): JSONResponse {
		try {
			$sink = $this->siem->createSink(adminUid: $this->actor(), params: (new SiemSinkRequest(request: $this->request))->forCreate());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $sink->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end createSink()

	/**
	 * Update a SIEM sink.
	 *
	 * @param string $id The sink id
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function updateSink(string $id): JSONResponse {
		try {
			$sink = $this->siem->updateSink(adminUid: $this->actor(), sinkId: $id, params: (new SiemSinkRequest(request: $this->request))->forUpdate());
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Sink not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $sink->jsonSerialize());
	}//end updateSink()

	/**
	 * Delete a SIEM sink.
	 *
	 * @param string $id The sink id
	 *
	 * @AuthorizedAdminSetting(AuditAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-returns-metadata-only
	 */
	#[AuthorizedAdminSetting(AuditAdminSettings::class)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroySink(string $id): JSONResponse {
		try {
			$this->siem->deleteSink(adminUid: $this->actor(), sinkId: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Sink not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['deleted' => true]);
	}//end destroySink()

	/**
	 * The acting administrator's uid.
	 *
	 * @return string
	 */
	private function actor(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end actor()
}//end class
