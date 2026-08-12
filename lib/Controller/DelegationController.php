<?php

/**
 * Doriath Delegation Controller
 *
 * Authenticated API controller for the SecretDelegation lifecycle:
 *  - index     list delegations for a secret
 *  - create    create a delegation (owner self-delegation)
 *  - handover  vault-admin power grab
 *  - reclaim   reclaim all temporary delegations
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

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for delegations.
 */
class DelegationController extends OCSController {
	/**
	 * Constructor for DelegationController.
	 *
	 * @param IRequest $request The request object
	 * @param DelegationService $delegationService The delegation service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private DelegationService $delegationService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List delegations for a secret.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.4
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$rows = $this->delegationService->getDelegationsForSecret(
				secretId: $secretId,
				ownerId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(
			data: array_map(static fn ($row) => $row->jsonSerialize(), $rows)
		);
	}//end index()

	/**
	 * Create a temporary delegation.
	 *
	 * @param string $secretId The secret ID
	 * @param string $delegatedTo The delegate's UID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.4
	 */
	#[NoAdminRequired]
	public function create(string $secretId, string $delegatedTo): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$entity = $this->delegationService->createDelegation(
				secretId: $secretId,
				delegatedTo: $delegatedTo,
				initiatedBy: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Take over a secret as a vault administrator (the "power grab").
	 *
	 * `user-sharing/spec.md` § Ownership Delegation names TWO ways a
	 * delegation may be created, and until now only one of them was
	 * reachable. `create()` routes to `DelegationService::createDelegation()`,
	 * which requires the caller to BE the owner; the admin path lives in
	 * `createAdminHandover()`, which was fully implemented, unit-tested and
	 * spec'd — and had no production caller at all. A vault administrator
	 * could not perform the takeover the spec says they MUST be able to
	 * perform, and nothing reported it, because a service method with no
	 * caller looks exactly like one that is simply never used.
	 *
	 * The two stay separate endpoints rather than one flag-selected write,
	 * mirroring the service: they are different authorization decisions, and
	 * a request body flag that switches which check runs is the shape that
	 * makes a takeover look like an ordinary delegation in the audit trail.
	 *
	 * The service enforces the rest — vault_admin membership, that the
	 * initiator is not already the owner, and that they already hold a share
	 * of the secret. A handover widens WHO may act on a secret already shared
	 * with the admin; it never grants reach over a secret they cannot see.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
	 */
	#[NoAdminRequired]
	public function handover(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$entity = $this->delegationService->createAdminHandover(
				secretId: $secretId,
				delegatedTo: $user->getUID(),
				initiatedBy: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end handover()

	/**
	 * What the calling user may do on the delegation surface.
	 *
	 * `index()` deliberately answers only to a secret's OWNER, so it cannot
	 * be the source of this: a vault admin looking at somebody else's secret
	 * gets a 403 from it. Without a separate read there is no way for the UI
	 * to know whether to offer the takeover, which is half of why the
	 * handover path stayed unreachable.
	 *
	 * Reports group membership only — never a per-secret decision. The
	 * per-secret preconditions live in the service and are enforced on the
	 * write, so a stale or spoofed `true` here buys nothing.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
	 */
	#[NoAdminRequired]
	public function capabilities(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: ['isVaultAdmin' => $this->delegationService->isVaultAdmin(userId: $user->getUID())]
		);
	}//end capabilities()

	/**
	 * Reclaim all temporary delegations for a secret.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.4
	 */
	#[NoAdminRequired]
	public function reclaim(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$removed = $this->delegationService->reclaimDelegation(
				secretId: $secretId,
				ownerId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: ['removed' => $removed]);
	}//end reclaim()
}//end class
