<?php

/**
 * Keepiq UseOnlyController
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\UseOnlyUseRecorder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Use recording for use-only copies (sharing-use-only-and-expiring-shares §3.3).
 */
class UseOnlyController extends Controller {

	/**
	 * Constructor for UseOnlyController.
	 *
	 * @param IRequest           $request     The request
	 * @param UseOnlyUseRecorder $recorder    The use recorder
	 * @param IUserSession       $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private UseOnlyUseRecorder $recorder,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Record that the caller's browser extension filled one of the caller's
	 * use-only copies. The recorder refuses any id the caller does not hold
	 * as a use-only copy with the answer an unknown id gets.
	 *
	 * Same authentication as the extension's other routes (app password, no
	 * CSRF token).
	 *
	 * @param string $id The recipient copy ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-each-use-is-recorded
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function used(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->recorder->recordUse(copyId: $id, userId: $user->getUID());
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['status' => 'recorded']);
	}//end used()
}//end class
