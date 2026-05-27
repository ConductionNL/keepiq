<?php

/**
 * Doriath CA Certificate Controller
 *
 * Admin-only controller for CA management.
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

use Exception;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCA\Doriath\Settings\AdminSettings;
use RuntimeException;

/**
 * Admin-only controller for CA management.
 */
class CACertificateController extends OCSController
{
    /**
     * Constructor for CACertificateController.
     *
     * @param IRequest                    $request   The request object
     * @param CertificateAuthorityService $caService The CA service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CertificateAuthorityService $caService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get CA health status.
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function getStatus(): JSONResponse
    {
        return new JSONResponse(data: $this->caService->getStatus());
    }//end getStatus()

    /**
     * Retry CA bootstrap.
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function retryBootstrap(): JSONResponse
    {
        try {
            $this->caService->retryBootstrap();
            return new JSONResponse(data: $this->caService->getStatus());
        } catch (RuntimeException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end retryBootstrap()

    /**
     * Force renew the intermediate certificate.
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function renewIntermediate(): JSONResponse
    {
        try {
            $count = $this->caService->renewIntermediate(forced: true);
            return new JSONResponse(
                data: [
                    'message'       => "Intermediate renewed, {$count} suites re-signed",
                    'resignedCount' => $count,
                    'status'        => $this->caService->getStatus(),
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end renewIntermediate()

    /**
     * Trigger root renewal.
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function renewRoot(): JSONResponse
    {
        try {
            $count = $this->caService->renewRoot();
            return new JSONResponse(
                data: [
                    'message'       => "Root renewed, {$count} suites re-signed",
                    'resignedCount' => $count,
                    'status'        => $this->caService->getStatus(),
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end renewRoot()
}//end class
