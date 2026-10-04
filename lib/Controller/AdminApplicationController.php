<?php

/**
 * Keepiq admin API: applications and machine access
 *
 * Part of the versioned admin API under /api/v1/admin (admin-public-api).
 * The Applications area: register, list, approve, reject and delete
 * applications, and set their lease policy.
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
use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Service\ApplicationService;
use OCA\Keepiq\Service\LeaseService;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * `/api/v1/admin/applications`. The area guard runs in Nextcloud's
 * middleware, so every service call below runs as an administrator.
 */
class AdminApplicationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param ApplicationService $applications The application service the admin screen uses
	 * @param LeaseService $leases The lease policy service
	 * @param ApplicationMapper $applicationMapper Existence check for the lease policy
	 * @param IUserSession $userSession The acting administrator
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private ApplicationService $applications,
		private LeaseService $leases,
		private ApplicationMapper $applicationMapper,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every application.
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(
			data: array_map(
				static fn (Application $application): array => $application->jsonSerialize(),
				$this->applications->listForUser($this->actor(), true)
			)
		);
	}//end index()

	/**
	 * One application, with its public certificate when it is active.
	 *
	 * @param string $id The application id
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	public function show(string $id): JSONResponse {
		try {
			$application = $this->applications->get($id, $this->actor(), true)->jsonSerialize();
		} catch (InvalidArgumentException $exception) {
			return $this->notFound(message: $exception->getMessage());
		}

		// The public certificate of an active application, so a script that
		// registered it from a CSR can read what Keepiq signed.
		$application['certificate'] = null;
		if ($application['status'] === 'active') {
			$application['certificate'] = $this->applications->getCertificate(applicationId: $id);
		}

		return new JSONResponse(data: $application);
	}//end show()

	/**
	 * Register an application. An administrator's registration is active at
	 * once, so it needs no separate approval (application-mgmt).
	 *
	 * @param string $name The application name
	 * @param string|null $description An optional description
	 * @param string $type internal or external
	 * @param string|null $csr An optional PKCS#10 CSR in PEM
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(
		string $name = '',
		?string $description = null,
		string $type = Application::TYPE_EXTERNAL,
		?string $csr = null,
	): JSONResponse {
		if (trim($name) === '') {
			return new JSONResponse(data: ['message' => 'name is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$entity = $this->applications->register(
				name: $name,
				description: $description,
				type: $type,
				csr: $csr,
				userId: $this->actor(),
				isAdmin: true
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Approve a pending application, recording the caller as approver.
	 *
	 * @param string $id The application id
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function approve(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->applications->approve(applicationId: $id, adminUserId: $this->actor(), isAdmin: true)->jsonSerialize());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end approve()

	/**
	 * Reject a pending application.
	 *
	 * @param string $id The application id
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function reject(string $id): JSONResponse {
		try {
			$this->applications->reject(applicationId: $id, adminUserId: $this->actor(), isAdmin: true);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['status' => 'rejected', 'id' => $id]);
	}//end reject()

	/**
	 * Delete an application and its vault.
	 *
	 * @param string $id The application id
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(string $id): JSONResponse {
		try {
			$this->applications->delete(applicationId: $id, isAdmin: true);
		} catch (InvalidArgumentException $exception) {
			return $this->notFound(message: $exception->getMessage());
		}

		return new JSONResponse(data: ['status' => 'deleted', 'id' => $id]);
	}//end destroy()

	/**
	 * The application's lease policy: its override and the effective values.
	 *
	 * @param string $id The application id
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	public function getLeasePolicy(string $id): JSONResponse {
		if ($this->exists(id: $id) === false) {
			return $this->notFound(message: 'Application not found');
		}

		return new JSONResponse(data: $this->leases->policyView(applicationId: $id));
	}//end getLeasePolicy()

	/**
	 * Set the application's lease policy override; null inherits.
	 *
	 * @param string $id The application id
	 * @param int|null $defaultTtl Default lease TTL in seconds, at least 60
	 * @param int|null $maxTtl Maximum lease TTL in seconds, at least 60
	 * @param bool|null $renewable Whether a lease may be renewed
	 *
	 * @AuthorizedAdminSetting(ApplicationAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/admin-api/spec.md#requirement-admin-api-covers-the-administration-jobs
	 */
	#[AuthorizedAdminSetting(ApplicationAdminSettings::class)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function setLeasePolicy(string $id, ?int $defaultTtl = null, ?int $maxTtl = null, ?bool $renewable = null): JSONResponse {
		if ($this->exists(id: $id) === false) {
			return $this->notFound(message: 'Application not found');
		}

		try {
			$this->leases->setPolicyOverride(applicationId: $id, defaultTtl: $defaultTtl, maxTtl: $maxTtl, renewable: $renewable);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $this->leases->policyView(applicationId: $id));
	}//end setLeasePolicy()

	/**
	 * The acting administrator's uid.
	 *
	 * @return string
	 */
	private function actor(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end actor()

	/**
	 * Whether an application exists.
	 *
	 * @param string $id The application id
	 *
	 * @return bool
	 */
	private function exists(string $id): bool {
		try {
			$this->applicationMapper->findById($id);
		} catch (DoesNotExistException) {
			return false;
		}

		return true;
	}//end exists()

	/**
	 * A 404 with a message.
	 *
	 * @param string $message The message
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $message): JSONResponse {
		return new JSONResponse(data: ['message' => $message], statusCode: Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
