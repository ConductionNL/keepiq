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
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\FederatedCertificateServiceInterface;
use OCA\Keepiq\Service\PartnerCertificateBatchService;
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
	 * @param FederatedCertificateServiceInterface $certificates The federated certificate lookup
	 * @param IUserSession $userSession The user session
	 * @param PartnerCertificateBatchService $batch The batch lookup over the caller's own partner shares
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private FederatedCertificateServiceInterface $certificates,
		private IUserSession $userSession,
		private PartnerCertificateBatchService $batch,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Look up a federated recipient's certificate at their partner instance.
	 * The answer is `{cloudId, certificate, chain, partnerRootFingerprint, valueFields}`, where `valueFields` lists
	 * the value fields beyond `key`, `login` and `additionalFields` the partner stores, empty for an older Keepiq.
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
	 * Look up the certificates of the caller's own active partner shares in one request, at most 100 distinct
	 * share ids. The answer is `{certificates: {shareId: <the single lookup's answer>}, refused: {shareId:
	 * {status, message}}}`: a share that is unknown, someone else's or not active is refused as `unknown_share`
	 * (404), and a lookup the partner refuses carries the single route's status and reason. The request counts
	 * once against the rate limit, as it answers only for shares the caller already shares.
	 *
	 * @param array<array-key,mixed> $shareIds The partner shares
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Answers only for partner shares whose owner is the caller
	 *   (PartnerCertificateBatchService::lookup() checks owner and status per share), and refuses every
	 *   other id with the same `unknown_share`, so no other user's share is revealed.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-the-owner-looks-up-their-partner-shares-certificates-in-one-request
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function recipientCertificates(array $shareIds = []): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$ids = array_values(array_filter($shareIds, static fn ($id): bool => is_string($id) && $id !== ''));
		if ($ids === []) {
			return new JSONResponse(data: ['message' => 'shareIds_required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$answer = $this->batch->lookup(shareIds: $ids, userId: $user->getUID());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// Both are maps by share id, also when empty: an empty PHP array would go out as a JSON list.
		return new JSONResponse(
			data: ['certificates' => (object)$answer['certificates'], 'refused' => (object)$answer['refused']]
		);
	}//end recipientCertificates()

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
