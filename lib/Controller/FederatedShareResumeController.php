<?php

/**
 * Keepiq Federated Share Resume Controller
 *
 * `POST /api/v1/federated-shares/{id}/resume`: the owner resumes a suspended
 * partner share with the current value, encrypted in the browser for the
 * certificate it verified again (sharing-atomic-share-save task 3.4).
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
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\FederatedShareResume;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Resumes the user's own suspended partner shares.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */
class FederatedShareResumeController extends Controller {
	/**
	 * Constructor for FederatedShareResumeController.
	 *
	 * @param IRequest $request The request
	 * @param FederatedShareResume $resumes The resume
	 * @param IUserSession $userSession The signed-in owner
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private FederatedShareResume $resumes,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resume a suspended share with a value encrypted now. The body carries
	 * `certFingerprint` (SHA-256 of the certificate the browser verified),
	 * `sourceUpdatedAt` (the version it encrypted from) and the ciphertext
	 * `key`, `login`, `additionalFields`, `totp` and `passkeys`.
	 *
	 * @param string $id The federated share
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
	 */
	#[NoAdminRequired]
	public function resume(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Only the caller's own suspended share; every other id is not found.
			$row = $this->resumes->requireOwnSuspended(shareId: $id, userId: $user->getUID());
			$share = $this->resumes->resume(row: $row, body: $this->request->getParams());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (ShareSaveRefusal $refusal) {
			return new JSONResponse(data: $refusal->getBody(), statusCode: $refusal->getStatus());
		} catch (PartnerUpgradeRequiredException $exception) {
			return $this->upgradeRequired(exception: $exception, shareId: $id);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Share not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $share->jsonSerialize());
	}//end resume()

	/**
	 * The refusal of a value the recipient's instance is too old to hold, as
	 * the share routes answer it.
	 *
	 * @param PartnerUpgradeRequiredException $exception The refusal
	 * @param string $shareId The federated share
	 *
	 * @return JSONResponse
	 */
	private function upgradeRequired(PartnerUpgradeRequiredException $exception, string $shareId): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => PartnerUpgradeRequiredException::CODE,
				'message' => $exception->getMessage(),
				'requiredVersion' => $exception->getRequiredVersion(),
				'shareId' => $shareId,
			],
			statusCode: Http::STATUS_CONFLICT
		);
	}//end upgradeRequired()
}//end class
