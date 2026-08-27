<?php

/**
 * Keepiq Ephemeral Send Access Controller
 *
 * Anonymous recipient surface (ephemeral-send §4.2), mirroring the
 * link-share two-phase protocol: `peek` (metadata), `access`
 * (ciphertext, view NOT yet consumed), `confirm` (successful decrypt →
 * consume + burn-at-cap), and `failure` (failed password attempt →
 * burn at 5). All `#[PublicPage]` + `#[AnonRateLimit]`; missing,
 * expired, and burned sends are indistinguishable 404s.
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
use OCA\Keepiq\Service\EphemeralSendService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public access endpoints for ephemeral sends.
 */
class EphemeralSendAccessController extends Controller {
	/**
	 * Constructor for EphemeralSendAccessController.
	 *
	 * @param IRequest $request The request object
	 * @param EphemeralSendService $service The send service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private EphemeralSendService $service,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Metadata for the access page — never the ciphertext.
	 *
	 * @param string $token The URL token
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 15, period: 60)]
	public function peek(string $token): JSONResponse {
		try {
			return new JSONResponse(data: $this->service->peek(token: $token));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}//end peek()

	/**
	 * The ciphertext (+ wrapped key) for client-side decryption; the
	 * view is consumed on `confirm`, not here.
	 *
	 * @param string $token The URL token
	 *
	 * @return JSONResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 15, period: 60)]
	public function access(string $token): JSONResponse {
		try {
			return new JSONResponse(data: $this->service->access(token: $token));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}//end access()

	/**
	 * Confirm a successful client-side decrypt: consume a view, burn at
	 * the cap.
	 *
	 * @param string $token The URL token
	 *
	 * @return JSONResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 15, period: 60)]
	public function confirm(string $token): JSONResponse {
		try {
			return new JSONResponse(data: $this->service->confirmView(token: $token));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}//end confirm()

	/**
	 * Report a failed password attempt; the send burns permanently at 5.
	 *
	 * @param string $token The URL token
	 *
	 * @return JSONResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 15, period: 60)]
	public function failure(string $token): JSONResponse {
		try {
			return new JSONResponse(data: $this->service->reportFailure(token: $token));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}//end failure()

	/**
	 * 404 — one shape for missing, expired, and burned sends.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Send not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()
}//end class
