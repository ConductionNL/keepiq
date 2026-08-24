<?php

/**
 * Keepiq Honey Controller
 *
 * Honey-credential endpoints (honey-credentials §4): flag/unflag a
 * decoy secret, read the flag state, list alerts (owner-scoped;
 * instance-wide for admins), acknowledge, and per-accessor snooze.
 * Every method declares an explicit auth attribute; owner/admin guards
 * run in the service (no IDOR). The flag never appears in any secret
 * response — only these owner/admin endpoints expose it.
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
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Service\HoneyCredentialService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Endpoints for honey (decoy) credentials.
 */
class HoneyController extends OCSController {
	/**
	 * Constructor for HoneyController.
	 *
	 * @param IRequest $request The request object
	 * @param HoneyCredentialService $service The honey service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager (admin scope)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private HoneyCredentialService $service,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The calling user's id, or null when unauthenticated.
	 *
	 * @return string|null
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * Whether the caller is an admin.
	 *
	 * @param string $uid The user id
	 *
	 * @return bool
	 */
	private function isAdmin(string $uid): bool {
		return $this->groupManager->isAdmin($uid);
	}//end isAdmin()

	/**
	 * Flag a secret as a decoy (owner or admin).
	 *
	 * @param string $id The secret UUID
	 * @param string $note Optional placement note
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
	 */
	#[NoAdminRequired]
	public function flag(string $id, string $note = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$noteValue = null;
			if ($note !== '') {
				$noteValue = $note;
			}

			$flagRow = $this->service->flag(secretId: $id, actorId: $uid, isAdmin: $this->isAdmin(uid: $uid), note: $noteValue);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: $flagRow->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end flag()

	/**
	 * Remove the decoy flag (owner or admin); alerts are kept.
	 *
	 * @param string $id The secret UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function unflag(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$this->service->unflag(secretId: $id, actorId: $uid, isAdmin: $this->isAdmin(uid: $uid));
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Secret is not flagged'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: ['unflagged' => true]);
	}//end unflag()

	/**
	 * The flag state of a secret for the owner/admin detail view.
	 *
	 * @param string $id The secret UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function status(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$flagRow = $this->service->getFlag(secretId: $id, actorId: $uid, isAdmin: $this->isAdmin(uid: $uid));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			data: [
				'flagged' => ($flagRow !== null),
				'flag' => $flagRow?->jsonSerialize(),
			]
		);
	}//end status()

	/**
	 * List alerts — owner: own decoys; admin: instance-wide.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function alerts(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			data: array_map(
				static fn (HoneyAlert $alert) => $alert->jsonSerialize(),
				$this->service->listAlerts(actorId: $uid, isAdmin: $this->isAdmin(uid: $uid))
			)
		);
	}//end alerts()

	/**
	 * Acknowledge an alert (decoy owner or admin).
	 *
	 * @param string $id The alert UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function acknowledge(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$alert = $this->service->acknowledge(alertId: $id, actorId: $uid, isAdmin: $this->isAdmin(uid: $uid));
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Alert not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: $alert->jsonSerialize());
	}//end acknowledge()

	/**
	 * Snooze future paging for the alert's accessor (owner or admin).
	 * The accessor keeps being audited while snoozed.
	 *
	 * @param string $id The alert UUID
	 * @param int $hours Snooze duration in hours
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function snooze(string $id, int $hours = 24): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$alert = $this->service->snooze(alertId: $id, actorId: $uid, isAdmin: $this->isAdmin(uid: $uid), hours: $hours);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Alert not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: $alert->jsonSerialize());
	}//end snooze()
}//end class
