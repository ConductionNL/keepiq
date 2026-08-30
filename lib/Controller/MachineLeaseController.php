<?php

/**
 * Keepiq Machine Lease Controller
 *
 * Bearer-authenticated lease surface for a registered application
 * (machine-secret-leases §4.1): list own leases, renew, and self-revoke
 * under `/api/v1/app/leases/*`. JwtAuthMiddleware resolves the calling
 * Application before any handler runs; cross-application access returns
 * the SAME 404 as a nonexistent lease (no existence oracle).
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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Bearer-authenticated lease lifecycle endpoints.
 */
class MachineLeaseController extends ApplicationApiController {
	/**
	 * Constructor for MachineLeaseController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param LeaseService $leaseService The lease service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private LeaseService $leaseService,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the calling application's leases, newest first.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function index(): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		return new JSONResponse(
			data: array_map(
				static fn (MachineLease $lease) => $lease->jsonSerialize(),
				$this->leaseService->listForApplication(applicationId: $application->getId())
			)
		);
	}//end index()

	/**
	 * Renew one of the calling application's leases.
	 *
	 * @param string $id The lease UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-renewal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function renew(string $id): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		try {
			$lease = $this->leaseService->renew(leaseId: $id, applicationId: $application->getId());
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(data: $lease->jsonSerialize());
	}//end renew()

	/**
	 * Self-revoke one of the calling application's leases.
	 *
	 * @param string $id The lease UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-revocation
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function revoke(string $id): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		try {
			$lease = $this->leaseService->loadOwned(leaseId: $id, applicationId: $application->getId());
		} catch (DoesNotExistException) {
			return $this->notFound();
		}

		$lease = $this->leaseService->revoke(lease: $lease, actor: 'self');

		return new JSONResponse(data: $lease->jsonSerialize());
	}//end revoke()

	/**
	 * 401 response for a missing/invalid Bearer token (defence in depth).
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Bearer token required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()

	/**
	 * 404 response — one shape for nonexistent AND foreign leases.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Lease not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()
}//end class
