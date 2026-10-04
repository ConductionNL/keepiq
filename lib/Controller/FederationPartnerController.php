<?php

/**
 * Keepiq Federation Partner Controller
 *
 * Administrator endpoints for the partner allowlist
 * (sharing-federated-recipients D1, task 1.3): read a partner's root
 * fingerprint, add it after comparing that fingerprint out of band, change
 * its directions, remove it. Every change needs a fresh password
 * confirmation.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Partner allowlist administration.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationPartnerController extends Controller {
	/**
	 * Constructor for FederationPartnerController.
	 *
	 * @param IRequest $request The request object
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param FederationRootService $root The local root and version gate
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private FederationPartnerService $partners,
		private FederationRootService $root,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The partners, this instance's own root fingerprint for the partner's
	 * administrator to compare, and whether this Nextcloud can federate.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(
			data: [
				'supported' => $this->root->isSupported(),
				'localRootFingerprint' => $this->root->localRootFingerprint(),
				'partners' => array_map(
					static fn ($partner): array => $partner->jsonSerialize(),
					$this->partners->all()
				),
			]
		);
	}//end index()

	/**
	 * Read a partner's root fingerprint from its discovery document, for the
	 * administrator to compare before adding it.
	 *
	 * @param string $url The partner address
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function preview(string $url = ''): JSONResponse {
		if ($this->root->isSupported() === false) {
			return $this->unsupported();
		}

		try {
			return new JSONResponse(data: $this->partners->preview(url: $url));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end preview()

	/**
	 * Add a partner with the fingerprint the administrator confirmed.
	 *
	 * @param string $url The partner address
	 * @param string $rootFingerprint The fingerprint the administrator compared
	 * @param bool|null $allowOutbound Whether users here may share to the partner (absent is no)
	 * @param bool|null $allowInbound Whether the partner may look up and deliver here (absent is no)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function create(
		string $url = '',
		string $rootFingerprint = '',
		?bool $allowOutbound = null,
		?bool $allowInbound = null,
	): JSONResponse {
		if ($this->root->isSupported() === false) {
			return $this->unsupported();
		}

		try {
			$partner = $this->partners->add(
				url: $url,
				confirmedFingerprint: $rootFingerprint,
				allowOutbound: $allowOutbound === true,
				allowInbound: $allowInbound === true,
				adminId: (string)$this->userSession->getUser()?->getUID(),
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $partner->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Change a partner's directions.
	 *
	 * @param string $id The partner UUID
	 * @param bool|null $allowOutbound Whether users here may share to the partner (absent is no)
	 * @param bool|null $allowInbound Whether the partner may look up and deliver here (absent is no)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function update(string $id, ?bool $allowOutbound = null, ?bool $allowInbound = null): JSONResponse {
		try {
			$partner = $this->partners->update(
				id: $id,
				allowOutbound: $allowOutbound === true,
				allowInbound: $allowInbound === true
			);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $partner->jsonSerialize());
	}//end update()

	/**
	 * Remove a partner.
	 *
	 * @param string $id The partner UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function destroy(string $id): JSONResponse {
		try {
			$this->partners->remove(id: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['removed' => $id]);
	}//end destroy()

	/**
	 * The answer below Nextcloud 33.
	 *
	 * @return JSONResponse
	 */
	private function unsupported(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Federation needs Nextcloud 33 or later'],
			statusCode: Http::STATUS_CONFLICT
		);
	}//end unsupported()
}//end class
