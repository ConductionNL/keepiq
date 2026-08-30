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
	 * Create a sink.
	 *
	 * @param string $name Display name
	 * @param string $type 'syslog' or 'webhook'
	 * @param string $endpoint host:port (syslog) or https URL (webhook)
	 * @param bool $tls TLS transport for syslog
	 * @param string $hmacSecret Optional write-only webhook HMAC secret
	 * @param array $categoryFilter Optional category slugs; empty = all
	 * @param int $queueCap Per-sink pending-queue cap
	 * @param bool $enabled Whether delivery is active
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $tls and $enabled are not behaviour
	 *   switches inside this method: they are two fields of the sink being created,
	 *   bound by name out of the JSON request body by the Nextcloud router and passed
	 *   straight into the params array. Nothing here branches on either. Removing them
	 *   would remove them from the HTTP contract.
	 */
	#[NoAdminRequired]
	public function create(
		string $name = '',
		string $type = '',
		string $endpoint = '',
		bool $tls = true,
		string $hmacSecret = '',
		array $categoryFilter = [],
		int $queueCap = 1000,
		bool $enabled = true,
	): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			$sink = $this->service->createSink(
				adminUid: $adminUid,
				params: [
					'name' => $name,
					'type' => $type,
					'endpoint' => $endpoint,
					'tls' => $tls,
					'hmacSecret' => $hmacSecret,
					'categoryFilter' => $categoryFilter,
					'queueCap' => $queueCap,
					'enabled' => $enabled,
				],
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $sink->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a sink; a blank hmacSecret preserves the stored one (§3.2).
	 *
	 * @param string $id The sink UUID
	 * @param string $name Display name
	 * @param string $endpoint Endpoint (blank preserves)
	 * @param bool|null $tls TLS transport, null preserves
	 * @param string $hmacSecret Write-only secret (blank preserves)
	 * @param array|null $categoryFilter Category slugs, null preserves
	 * @param int|null $queueCap Queue cap, null preserves
	 * @param bool|null $enabled Active flag, null preserves
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function update(
		string $id,
		string $name = '',
		string $endpoint = '',
		?bool $tls = null,
		string $hmacSecret = '',
		?array $categoryFilter = null,
		?int $queueCap = null,
		?bool $enabled = null,
	): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		$params = $this->collectSinkChanges(
			name: $name,
			endpoint: $endpoint,
			tls: $tls,
			hmacSecret: $hmacSecret,
			categoryFilter: $categoryFilter,
			queueCap: $queueCap,
			enabled: $enabled
		);

		try {
			$sink = $this->service->updateSink(adminUid: $adminUid, sinkId: $id, params: $params);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Sink not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $sink->jsonSerialize());
	}//end update()

	/**
	 * Collect the sink fields the caller actually supplied. An empty string
	 * or a null means "leave unchanged"; hmacSecret is always forwarded
	 * because the service treats '' as "keep the stored secret".
	 *
	 * @param string $name The new display name, or ''
	 * @param string $endpoint The new endpoint URL, or ''
	 * @param bool|null $tls The new TLS flag, or null
	 * @param string $hmacSecret The new HMAC secret, or ''
	 * @param array<int,string>|null $categoryFilter The new category filter, or null
	 * @param int|null $queueCap The new queue cap, or null
	 * @param bool|null $enabled The new enabled flag, or null
	 *
	 * @return array<string,mixed> The changed fields only.
	 */
	private function collectSinkChanges(
		string $name,
		string $endpoint,
		?bool $tls,
		string $hmacSecret,
		?array $categoryFilter,
		?int $queueCap,
		?bool $enabled,
	): array {
		$params = ['hmacSecret' => $hmacSecret];
		if ($name !== '') {
			$params['name'] = $name;
		}

		if ($endpoint !== '') {
			$params['endpoint'] = $endpoint;
		}

		if ($tls !== null) {
			$params['tls'] = $tls;
		}

		if ($categoryFilter !== null) {
			$params['categoryFilter'] = $categoryFilter;
		}

		if ($queueCap !== null) {
			$params['queueCap'] = $queueCap;
		}

		if ($enabled !== null) {
			$params['enabled'] = $enabled;
		}

		return $params;
	}//end collectSinkChanges()

	/**
	 * Delete a sink and its queued events.
	 *
	 * @param string $id The sink UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
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
