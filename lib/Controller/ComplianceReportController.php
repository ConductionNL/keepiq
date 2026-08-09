<?php

/**
 * Doriath Compliance Report Controller
 *
 * Admin-only compliance surface (compliance-reporting §4): generate a
 * snapshot, list/show snapshots, warm metrics, and the export beacon.
 * Every method rejects a non-admin caller BEFORE any report logic runs.
 *
 * @category Controller
 * @package  OCA\Doriath\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\ComplianceReport;
use OCA\Doriath\Service\ComplianceReportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin endpoints for compliance reporting.
 */
class ComplianceReportController extends OCSController
{
    /**
     * Constructor for ComplianceReportController.
     *
     * @param IRequest                $request      The request object
     * @param ComplianceReportService $service      The compliance service
     * @param IUserSession            $userSession  The user session
     * @param IGroupManager           $groupManager The group manager (admin gate)
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ComplianceReportService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The admin uid, or null for any non-admin caller (§7.3 — the gate
     * runs before any report logic).
     *
     * @return string|null
     */
    private function adminUid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return null;
        }

        return $user->getUID();
    }//end adminUid()

    /**
     * 403 for non-admin callers.
     *
     * @return JSONResponse
     */
    private function forbidden(): JSONResponse
    {
        return new JSONResponse(
            data: ['message' => 'Compliance reporting is admin-only'],
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end forbidden()

    /**
     * Generate + persist an immutable snapshot.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
     */
    #[NoAdminRequired]
    public function generate(): JSONResponse
    {
        $adminUid = $this->adminUid();
        if ($adminUid === null) {
            return $this->forbidden();
        }

        return new JSONResponse(
            data: $this->service->generate(adminUid: $adminUid)->jsonSerialize(),
            statusCode: Http::STATUS_CREATED
        );
    }//end generate()

    /**
     * List snapshots (metadata only, newest first).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->adminUid() === null) {
            return $this->forbidden();
        }

        return new JSONResponse(
            data: array_map(
                static fn (ComplianceReport $report) => [
                    'id'          => $report->getId(),
                    'generatedBy' => $report->getGeneratedBy(),
                    'generatedAt' => $report->getGeneratedAt()?->format('c'),
                    'appVersion'  => $report->getAppVersion(),
                ],
                $this->service->listReports()
            )
        );
    }//end index()

    /**
     * One snapshot with its sections.
     *
     * @param string $id The report UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->adminUid() === null) {
            return $this->forbidden();
        }

        try {
            return new JSONResponse(data: $this->service->getReport(id: $id)->jsonSerialize());
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Report not found'], statusCode: Http::STATUS_NOT_FOUND);
        }
    }//end show()

    /**
     * The warm metrics cache for the live posture card.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function metrics(): JSONResponse
    {
        if ($this->adminUid() === null) {
            return $this->forbidden();
        }

        return new JSONResponse(data: $this->service->cachedMetrics());
    }//end metrics()

    /**
     * Export beacon: the CLIENT rendered the export; the server records
     * the audit event only (§4.3).
     *
     * @param string $id     The exported report UUID
     * @param string $format The export format (csv|pdf)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function exported(string $id, string $format=''): JSONResponse
    {
        $adminUid = $this->adminUid();
        if ($adminUid === null) {
            return $this->forbidden();
        }

        if (in_array($format, ['csv', 'pdf'], true) === false) {
            return new JSONResponse(data: ['message' => 'format must be csv or pdf'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $this->service->recordExport(adminUid: $adminUid, reportId: $id, format: $format);

        return new JSONResponse(data: ['recorded' => true]);
    }//end exported()
}//end class
