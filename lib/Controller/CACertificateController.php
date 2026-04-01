<?php

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Admin-only controller for CA management.
 */
class CACertificateController extends OCSController
{
    public function __construct(
        IRequest $request,
        private CertificateAuthorityService $caService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * Get CA health status.
     */
    public function getStatus(): JSONResponse
    {
        return new JSONResponse($this->caService->getStatus());
    }//end getStatus()

    /**
     * Retry CA bootstrap.
     */
    public function retryBootstrap(): JSONResponse
    {
        try {
            $this->caService->retryBootstrap();
            return new JSONResponse($this->caService->getStatus());
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end retryBootstrap()

    /**
     * Force renew the intermediate certificate.
     */
    public function renewIntermediate(): JSONResponse
    {
        try {
            $count = $this->caService->renewIntermediate(forced: true);
            return new JSONResponse([
                'message'       => "Intermediate renewed, {$count} suites re-signed",
                'resignedCount' => $count,
                'status'        => $this->caService->getStatus(),
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end renewIntermediate()

    /**
     * Trigger root renewal.
     */
    public function renewRoot(): JSONResponse
    {
        try {
            $count = $this->caService->renewRoot();
            return new JSONResponse([
                'message'       => "Root renewed, {$count} suites re-signed",
                'resignedCount' => $count,
                'status'        => $this->caService->getStatus(),
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end renewRoot()
}//end class
