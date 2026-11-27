<?php

/**
 * Keepiq CA Certificate Controller
 *
 * Admin-only controller for CA management.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use Exception;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\CertificateAuthorityServiceInterface;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use RuntimeException;

/**
 * Admin-only controller for CA management.
 */
class CACertificateController extends OCSController {
	/**
	 * Constructor for CACertificateController.
	 *
	 * @param IRequest $request The request object
	 * @param CertificateAuthorityServiceInterface $caService The CA service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private CertificateAuthorityServiceInterface $caService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get CA health status.
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getStatus(): JSONResponse {
		return new JSONResponse(data: $this->caService->getStatus());
	}//end getStatus()

	/**
	 * CA health with issued-certificate counts (certificate-lifecycle
	 * §4.2) — the same status payload, published under the lifecycle
	 * route. Counts only; no identifiers, PEM, or key material.
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-ca-health-on-the-admin-dashboard
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function health(): JSONResponse {
		return new JSONResponse(data: $this->caService->getStatus());
	}//end health()

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
	public function retryBootstrap(): JSONResponse {
		try {
			$this->caService->retryBootstrap();
			return new JSONResponse(data: $this->caService->getStatus());
		} catch (RuntimeException $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_INTERNAL_SERVER_ERROR);
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
	public function renewIntermediate(): JSONResponse {
		try {
			$count = $this->caService->renewIntermediateRevokingOld();
			return new JSONResponse(
				data: [
					'message' => "Intermediate renewed, {$count} suites re-signed",
					'resignedCount' => $count,
					'status' => $this->caService->getStatus(),
				]
			);
		} catch (Exception $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end renewIntermediate()

	/**
	 * Trigger root renewal. `announced` is false when the old root's key could not be read: partners are not told, and
	 * each has to re-pin the new root by hand after comparing `rootFingerprint` out of band.
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-a-root-whose-key-cannot-be-read-is-renewed-unannounced
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function renewRoot(): JSONResponse {
		try {
			$renewal = $this->caService->renewRoot();
			$count   = $renewal['resignedCount'];
			$message = "Root renewed, {$count} suites re-signed";
			if ($renewal['announced'] === false) {
				$message .= '. Partners were not told: each has to re-pin this server by hand after an out-of-band fingerprint check.';
			}

			return new JSONResponse(
				data: [
					'message' => $message,
					'resignedCount' => $count,
					'announced' => $renewal['announced'],
					'rootFingerprint' => $renewal['rootFingerprint'],
					'status' => $this->caService->getStatus(),
				]
			);
		} catch (Exception $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end renewRoot()
}//end class
