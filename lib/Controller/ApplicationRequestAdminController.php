<?php

/**
 * Doriath - Administrator view of an application's secret requests
 *
 * An application can ask a human to submit a credential into its own vault, and
 * until now no person could see that it had. `created_by` for such a request is
 * `application:<id>`, the user-facing listing matches `created_by` against the
 * acting user's id, and the target Secrets are application-owned so they appear
 * in no user's vault. The only lister was the application's own Bearer-
 * authenticated endpoint: the application could enumerate its requests, nobody
 * else could. Creation was audited, so the events existed with nowhere to read
 * them as state.
 *
 * That left an administrator unable to answer basic questions about software they
 * approved — what credentials is it asking humans for, and is a fill link
 * circulating that should be ended. A fill link is a bearer credential in a URL;
 * "nobody can enumerate them" is not a security property there, it is an audit
 * gap.
 *
 * A controller of its own rather than two more methods on ApplicationController,
 * which is already at the public-method limit, and rather than an addition to
 * ApplicationSecretRequestsController, which is the MACHINE surface: that one
 * authenticates a Bearer token and is scoped to the caller's own vault, and this
 * one authenticates a human administrator. Sharing a class would put two
 * different authorities one typo apart.
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
use OCA\Doriath\AppInfo\Application as DoriathApp;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Service\ApplicationRequestAdminService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Admin-scoped reads and revokes over an application's secret requests.
 */
class ApplicationRequestAdminController extends Controller {
	/**
	 * Constructor for ApplicationRequestAdminController.
	 *
	 * @param IRequest $request The request
	 * @param ApplicationRequestAdminService $service The admin-scoped request service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager Resolves administrator membership
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		IRequest $request,
		private ApplicationRequestAdminService $service,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: DoriathApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The acting administrator's uid, or null for any non-admin caller.
	 *
	 * Deliberately answers a single question — "is this an administrator, and
	 * who" — so no endpoint below can accidentally proceed on a truthy value that
	 * merely means "logged in". Follows SiemSinkController, the closest neighbour
	 * for an admin-only JSON surface.
	 *
	 * Not scoped to whoever registered the application: its vault belongs to no
	 * single user, and registration is a historical act rather than continuing
	 * responsibility. Tying audit visibility to it would make the answer to "who
	 * may see this" depend on who happened to click Register months ago, and the
	 * registrar may have left.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
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
	 *
	 * @spec exclude Response shaping for the guard above.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Viewing an application\'s secret requests is admin-only'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()

	/**
	 * The HTTP status a refusal should answer with.
	 *
	 * The service's own code is preserved rather than flattened: 404 for a request
	 * belonging to another actor and 403 for a non-administrator say different
	 * things, and collapsing both into 400 would leave the caller unable to tell
	 * "fix the id" from "stop asking".
	 *
	 * @param int $code The exception's code
	 * @param int $fallback The status to use when the exception carried none
	 *
	 * @return int
	 *
	 * @spec exclude Response shaping; the codes themselves are asserted in the
	 *   service and controller tests.
	 */
	private function statusFor(int $code, int $fallback): int {
		if ($code > 0) {
			return $code;
		}

		return $fallback;
	}//end statusFor()

	/**
	 * Every secret request an application has created.
	 *
	 * `#[NoAdminRequired]` with the check in the body is the deliberate pairing,
	 * not a contradiction: it lets the request reach this method so a non-admin
	 * receives a JSON 403 the admin UI can render, instead of the framework's
	 * login redirect arriving where fetch() expected JSON. The authorization is
	 * asserted twice on purpose — here, and again inside the service — so a future
	 * endpoint cannot expose the listing by carrying the wrong annotation.
	 *
	 * Every status is returned, not only pending. "What has this application been
	 * asking people for" is the audit question; a list of only what is outstanding
	 * answers a narrower one.
	 *
	 * @param string $id The application id
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			$requests = $this->service->listForApplication(applicationId: $id, isAdmin: true);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: $this->statusFor(code: $e->getCode(), fallback: Http::STATUS_BAD_REQUEST)
			);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Could not list the application\'s secret requests'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			data: array_map(
				static fn (SecretRequest $request): array => $request->jsonSerialize(),
				$requests
			)
		);
	}//end index()

	/**
	 * Revoke one of an application's secret requests.
	 *
	 * The reason the change exists: a circulating fill link needs an off switch
	 * that does not depend on the application cooperating.
	 *
	 * The application id is part of the path and is enforced against the request's
	 * `created_by` in the service, so this cannot be used to revoke an arbitrary
	 * request by id through an application route.
	 *
	 * @param string $id The application id
	 * @param string $requestId The request to revoke
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	#[NoAdminRequired]
	public function destroy(string $id, string $requestId): JSONResponse {
		$adminUid = $this->adminUid();
		if ($adminUid === null) {
			return $this->forbidden();
		}

		try {
			$revoked = $this->service->revokeForApplication(
				requestId: $requestId,
				applicationId: $id,
				adminUserId: $adminUid,
				isAdmin: true
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: $this->statusFor(code: $e->getCode(), fallback: Http::STATUS_BAD_REQUEST)
			);
		} catch (Throwable) {
			// A missing request id lands here via the mapper's lookup exception.
			return new JSONResponse(
				data: ['message' => 'Request not found for this application'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $revoked->jsonSerialize());
	}//end destroy()
}//end class
