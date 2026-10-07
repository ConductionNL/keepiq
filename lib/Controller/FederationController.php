<?php

/**
 * Keepiq Federation Controller
 *
 * The owner-facing certificate lookup for a federated recipient
 * (sharing-federated-recipients D3, task 2.2). The owner's browser sends a
 * cloud id; this server asks the outbound partner through a signed OCM
 * request and returns the certificate, its chain and the pinned root
 * fingerprint, which the browser verifies before it encrypts anything.
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
use OCA\Keepiq\Service\FederatedCertificateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Federated recipient certificate lookup for vault owners.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
class FederationController extends Controller {
	/**
	 * Constructor for FederationController.
	 *
	 * @param IRequest $request The request object
	 * @param FederatedCertificateService $certificates The federated certificate lookup
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private FederatedCertificateService $certificates,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Look up a federated recipient's certificate at their partner instance.
	 *
	 * @param string $cloudId The recipient's cloud id
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Reads no object of this instance: the cloud id names a user on
	 *   another instance, the outbound partner allowlist in FederatedCertificateService::lookup()
	 *   decides whether any call is made, and the partner answers only through its own inbound
	 *   and opt-in checks.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function recipientCertificate(string $cloudId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->certificates->lookup(cloudId: $cloudId, userId: $user->getUID()));
		} catch (InvalidArgumentException $exception) {
			$status = Http::STATUS_NOT_FOUND;
			if ($exception->getMessage() === 'not_a_partner') {
				$status = Http::STATUS_FORBIDDEN;
			}

			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: $status);
		} catch (RuntimeException $exception) {
			$status = Http::STATUS_BAD_GATEWAY;
			if ($exception->getMessage() === 'federation_unavailable') {
				$status = Http::STATUS_CONFLICT;
			}

			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: $status);
		}//end try
	}//end recipientCertificate()

	/**
	 * Whether the share dialog may offer a recipient at another
	 * organisation.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Reads no object: it answers whether any outbound partner exists,
	 *   the same for every signed-in user.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-no-partner-no-federation
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: ['outbound' => $this->certificates->outboundAvailable()]);
	}//end status()
}//end class
