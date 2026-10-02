<?php

/**
 * Keepiq SIEM Sink Controller
 *
 * Admin-only SIEM sink management (siem-audit-export §6): list, create,
 * update, delete, and test-fire sinks. Every method rejects a non-admin
 * caller BEFORE any sink logic runs; serialized sinks expose whether an
 * HMAC secret is set but NEVER the secret itself.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Service\Siem\SiemSinkRequest;
use OCA\Keepiq\Service\SiemService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin endpoints for SIEM sink management.
 */
class SiemSinkController extends OCSController {
	/**
	 * Constructor for SiemSinkController.
	 *
	 * @param IRequest $request The request object
	 * @param SiemService $service The SIEM service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager (admin gate)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SiemService $service,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The admin uid, or null for any non-admin caller (§6.2 — the gate
	 * runs before any sink logic).
	 *
	 * @return string|null
	 */
	private function adminUid(): ?string {
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
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'SIEM sink management is admin-only'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()

	/**
	 * All sinks with delivery state (secret never serialized).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/siem-audit-export/specs/siem-audit-export/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->adminUid() === null) {
			return $this->forbidden();
		}

		return new JSONResponse(
			data: array_map(
				static fn (SiemSink $sink) => $sink->jsonSerialize(),
				$this->service->listSinks()
			)
		);
	}//end index()

	/**
	 * Create a sink. The body carries name, type (syslog, webhook, splunk_hec
	 * or sentinel), endpoint, tls, hmacSecret, categoryFilter, queueCap,
	 * enabled, format, credential and connectorOptions; SiemSinkRequest reads
	 * them with their defaults.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			$sink = $this->service->createSink(
				adminUid: $adminUid,
				params: (new SiemSinkRequest(request: $this->request))->forCreate(),
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $sink->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a sink with the fields the body supplies; a blank hmacSecret or
	 * credential keeps the stored one (§3.2).
	 *
	 * @param string $id The sink UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-connector-credentials-are-write-only-and-encrypted-at-rest
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			$sink = $this->service->updateSink(
				adminUid: $adminUid,
				sinkId: $id,
				params: (new SiemSinkRequest(request: $this->request))->forUpdate()
			);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Sink not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $sink->jsonSerialize());
	}//end update()

	/**
	 * Delete a sink and its queued events.
	 *
	 * @param string $id The sink UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			$this->service->deleteSink(adminUid: $adminUid, sinkId: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Sink not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['deleted' => true]);
	}//end destroy()

	/**
	 * Test-fire a synthetic payload at a sink.
	 *
	 * @param string $id The sink UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	#[NoAdminRequired]
	public function test(string $id): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			return new JSONResponse(data: $this->service->testSink(adminUid: $adminUid, sinkId: $id));
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Sink not found'], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end test()
}//end class
