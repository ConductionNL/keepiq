<?php

/**
 * Keepiq Recipient Status Controller
 *
 * Marks, for the users in one sharee search result, which can receive a
 * share yet (keepiq#37). There is deliberately no endpoint that lists the
 * users who hold a vault: the answer covers only ids the caller's own
 * sharee search returns.
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
use OCA\Keepiq\Service\RecipientStatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Recipient status for the share dialog's user search.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
 */
class RecipientStatusController extends Controller {
	/**
	 * Constructor for RecipientStatusController.
	 *
	 * @param IRequest $request The request object
	 * @param RecipientStatusService $recipientStatus The recipient status service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private RecipientStatusService $recipientStatus,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Which of the given users, all from the caller's sharee search for
	 * `search`, hold an active vault. Ids that search does not return are
	 * left out of the answer, so the endpoint cannot be used to probe users
	 * the caller may not share with.
	 *
	 * @param string $search The term the caller searched for
	 * @param array<int,mixed> $userIds The user ids from that search's result
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Reads no object by a caller-chosen id: RecipientStatusService::statusFor()
	 *   reruns Nextcloud's sharee search as the caller and answers only for ids that search returns,
	 *   which is exactly the set the caller may already see in the share dialog.
	 *
	 * @return JSONResponse `{recipients: [{userId, hasSuite}]}`
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
	 */
	#[NoAdminRequired]
	public function status(string $search = '', array $userIds = []): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$requested = array_values(
			array_unique(
				array_filter(
					$userIds,
					static fn (mixed $candidate): bool => (is_string($candidate) === true && $candidate !== '')
				)
			)
		);
		if (count($requested) > RecipientStatusService::MAX_USERS) {
			return new JSONResponse(
				data: [
					'message' => sprintf(
						'At most %d users may be looked up at once, %d given',
						RecipientStatusService::MAX_USERS,
						count($requested)
					),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: ['recipients' => $this->recipientStatus->statusFor(search: $search, userIds: $requested)]
		);
	}//end status()
}//end class
