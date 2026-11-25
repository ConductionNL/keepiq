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
	 * Resume a suspended share with a value encrypted now.
	 *
	 * @param string $id The federated share
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified
	 * @param string $sourceUpdatedAt The version of the secret the browser encrypted from
	 * @param string $key The value, encrypted for the recipient
	 * @param string|null $login The login, encrypted for the recipient
	 * @param string|null $additionalFields The additional fields, encrypted for the recipient
	 * @param string|null $totp The TOTP seed, encrypted for the recipient
	 * @param string|null $passkeys The passkeys, encrypted for the recipient
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One parameter per request field, as Nextcloud binds them.
	 */
	#[NoAdminRequired]
	public function resume(
		string $id,
		string $certFingerprint = '',
		string $sourceUpdatedAt = '',
		string $key = '',
		?string $login = null,
		?string $additionalFields = null,
		?string $totp = null,
		?string $passkeys = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$share = $this->resumes->resume(
				shareId: $id,
				userId: $user->getUID(),
				certFingerprint: $certFingerprint,
				ciphertext: [
					'key' => $key,
					'login' => $login,
					'additionalFields' => $additionalFields,
					'totp' => $totp,
					'passkeys' => $passkeys,
				],
				sourceUpdatedAt: $sourceUpdatedAt,
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (ShareSaveRefusal $refusal) {
			return new JSONResponse(data: $refusal->getBody(), statusCode: $refusal->getStatus());
		} catch (PartnerUpgradeRequiredException $exception) {
			return new JSONResponse(
				data: [
					'error' => PartnerUpgradeRequiredException::CODE,
					'message' => $exception->getMessage(),
					'requiredVersion' => $exception->getRequiredVersion(),
					'shareId' => $id,
				],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Share not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $share->jsonSerialize());
	}//end resume()
}//end class
