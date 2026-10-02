<?php

/**
 * Keepiq Lease Admin Controller
 *
 * Session-authenticated lease management (machine-secret-leases §4.2):
 * an application's lease list for its registrant or an admin, and
 * admin/owner revocation. Per-object guard: NC admins and the
 * application's registrant only; everyone else gets the same 404 as a
 * nonexistent application/lease.
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
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Db\MachineLeaseMapper;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\LeaseService;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Session-authenticated admin/owner lease management.
 */
class LeaseAdminController extends OCSController {
	/**
	 * Constructor for LeaseAdminController.
	 *
	 * @param IRequest $request The request object
	 * @param LeaseService $leaseService The lease service
	 * @param MachineLeaseMapper $leaseMapper The lease mapper
	 * @param ApplicationMapper $applicationMapper The application mapper (owner guard)
	 * @param IUserSession $userSession The user session
	 * @param AdminAreaAuthorizer $areas Whether the caller holds the Applications admin area
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private LeaseService $leaseService,
		private MachineLeaseMapper $leaseMapper,
		private ApplicationMapper $applicationMapper,
		private IUserSession $userSession,
		private AdminAreaAuthorizer $areas,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * An application's leases (admin or registrant only).
	 *
	 * @param string $id The application id
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->mayManageApplication(applicationId: $id, userId: $userId) === false) {
			return $this->notFound();
		}

		return new JSONResponse(
			data: array_map(
				static fn (MachineLease $lease) => $lease->jsonSerialize(),
				$this->leaseService->listForApplication(applicationId: $id)
			)
		);
	}//end index()

	/**
	 * Revoke a lease (admin or the leased application's registrant).
	 *
	 * @param string $leaseId The lease UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-revocation
	 */
	#[NoAdminRequired]
	public function revoke(string $leaseId): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$lease = $this->leaseMapper->findById($leaseId);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}

		if ($this->mayManageApplication(applicationId: $lease->getApplicationId(), userId: $userId) === false) {
			return $this->notFound();
		}

		$lease = $this->leaseService->revoke(lease: $lease, actor: $userId);

		return new JSONResponse(data: $lease->jsonSerialize());
	}//end revoke()

	/**
	 * Read an application's lease policy (admin or registrant only).
	 *
	 * Answers the effective policy, the stored override and the instance
	 * values, plus whether the caller may change it: only an admin may
	 * (setPolicy()), the registrant sees it read-only. Everyone else gets
	 * the same 404 as a nonexistent application.
	 *
	 * @param string $id The application id
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
	 */
	#[NoAdminRequired]
	public function getPolicy(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->mayManageApplication(applicationId: $id, userId: $userId) === false) {
			return $this->notFound();
		}

		// An admin passes the guard without a lookup, so check existence here:
		// a policy view for an application that does not exist is a 404 too.
		try {
			$this->applicationMapper->findById($id);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}

		return new JSONResponse(
			data: array_merge(
				$this->leaseService->policyView(applicationId: $id),
				['canEdit' => $this->areas->holds(userId: $userId, areaClass: ApplicationAdminSettings::class)]
			)
		);
	}//end getPolicy()

	/**
	 * Store a per-application lease-policy override (admin only).
	 *
	 * @param string $id The application id
	 * @param int|null $defaultTtl Default TTL seconds (null = inherit)
	 * @param int|null $maxTtl Max TTL seconds (null = inherit)
	 * @param bool|null $renewable Renewability (null = inherit)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
	 */
	#[NoAdminRequired]
	public function setPolicy(string $id, ?int $defaultTtl = null, ?int $maxTtl = null, ?bool $renewable = null): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->areas->holds(userId: $userId, areaClass: ApplicationAdminSettings::class) === false) {
			return $this->notFound();
		}

		try {
			$this->applicationMapper->findById($id);
			$this->leaseService->setPolicyOverride(
				applicationId: $id,
				defaultTtl: $defaultTtl,
				maxTtl: $maxTtl,
				renewable: $renewable,
			);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $this->leaseService->effectivePolicy(applicationId: $id));
	}//end setPolicy()

	/**
	 * Whether the session user may manage an application's leases: NC
	 * admins and the application's registrant.
	 *
	 * @param string $applicationId The application id
	 * @param string $userId The session user
	 *
	 * @return bool
	 */
	private function mayManageApplication(string $applicationId, string $userId): bool {
		if ($this->areas->holds(userId: $userId, areaClass: ApplicationAdminSettings::class) === true) {
			return true;
		}

		try {
			$application = $this->applicationMapper->findById($applicationId);
		} catch (DoesNotExistException) {
			return false;
		}

		return $application->getRegisteredBy() === $userId;
	}//end mayManageApplication()

	/**
	 * Resolve the session user ID or null when unauthenticated.
	 *
	 * @return string|null
	 */
	private function sessionUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end sessionUserId()

	/**
	 * 404 response — one shape for nonexistent and unauthorized objects.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()
}//end class
