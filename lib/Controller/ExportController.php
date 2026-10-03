<?php

/**
 * Keepiq Export Controller
 *
 * Receives the client's report of a completed vault export and emits the typed
 * SecretExportedEvent for the session user (secret-export-gdpr D5). Because the
 * export itself runs entirely client-side under the always-E2E model (ADR-003),
 * the server only learns of an export when the browser reports it BEFORE the
 * file download is offered. This is honest-client accountability: a tampered
 * client can already read every secret through the normal API, so the event
 * covers the supported UI flows, which is exactly what a UI audit can promise.
 *
 * The endpoint never receives plaintext secret material, the backup passphrase,
 * or any derived key — only the mode, scope, and secret count.
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Event\SecretExportedEvent;
use OCA\Keepiq\Service\VaultPolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for client-reported export events.
 */
class ExportController extends Controller {
	/**
	 * The accepted export modes.
	 *
	 * @var string[]
	 */
	private const MODES = ['encrypted-backup', 'plaintext-csv', 'cxf', 'cxp'];

	/**
	 * The accepted export scopes.
	 *
	 * @var string[]
	 */
	private const SCOPES = ['vault', 'folders'];

	/**
	 * Constructor for ExportController.
	 *
	 * @param IRequest $request The request
	 * @param IUserSession $userSession The user session
	 * @param IEventDispatcher $dispatcher The event dispatcher
	 * @param VaultPolicyService $vaultPolicies The vault policies (export ban)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IEventDispatcher $dispatcher,
		private VaultPolicyService $vaultPolicies,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Record a completed export by dispatching SecretExportedEvent.
	 *
	 * Self-scoped: the event is always emitted for the authenticated session
	 * user; the request body carries no user selector. Validates the mode and
	 * scope enums and the non-negative count; rejects anything else with 400.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secret-export/spec.md
	 * @spec openspec/changes/admin-vault-policies/tasks.md#2.1
	 *
	 * @no-admin-idor-exempt no object is addressed. The three parameters are an
	 * export mode, a scope and a count, each validated against a fixed
	 * allow-list; nothing is loaded by id. The audit event it dispatches is
	 * stamped with $user->getUID() from the session, not from the request, so a
	 * caller cannot record an export against another user.
	 */
	#[NoAdminRequired]
	public function events(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// The export ban (admin-vault-policies D2). Every export mode reports
		// here BEFORE the browser offers the file, so refusing the report
		// aborts the download. The GDPR access package never reports a mode
		// and stays available.
		if ($this->vaultPolicies->appliesTo(policy: VaultPolicyService::EXPORT_DISABLED, userId: $user->getUID()) === true) {
			return new JSONResponse(
				data: [
					'message' => 'Your organisation does not allow exporting your personal vault',
					'code' => 'export_disabled_by_policy',
				],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$mode = (string)$this->request->getParam('mode', '');
		$scope = (string)$this->request->getParam('scope', '');
		$count = (int)$this->request->getParam('secretCount', 0);

		if (in_array($mode, self::MODES, true) === false
			|| in_array($scope, self::SCOPES, true) === false
			|| $count < 0
		) {
			return new JSONResponse(
				data: ['message' => 'Invalid export mode, scope, or count'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$this->dispatcher->dispatchTyped(
			new SecretExportedEvent(
				userId: $user->getUID(),
				mode: $mode,
				scope: $scope,
				secretCount: $count,
			)
		);

		return new JSONResponse(data: ['recorded' => true]);
	}//end events()
}//end class
