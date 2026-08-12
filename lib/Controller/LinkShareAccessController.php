<?php

/**
 * Doriath Link Share Access Controller
 *
 * Public (unauthenticated) API controller for the two-phase link share
 * access protocol used by the external recipient's browser.
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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\LinkShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Throwable;

/**
 * Public API controller for link share access (Phase 1 + Phase 2).
 */
class LinkShareAccessController extends OCSController {
	/**
	 * Constructor for LinkShareAccessController.
	 *
	 * @param IRequest $request The request object
	 * @param LinkShareService $linkShareService The link share service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private LinkShareService $linkShareService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Phase 1: fetch the encrypted blob and salt for a token.
	 *
	 * The optional `failed` flag lets the browser report a prior decryption
	 * failure (incorrect password) so the server can increment the
	 * brute-force counter before returning the next blob. Every error case
	 * returns a uniform 404 with a generic message to prevent token
	 * enumeration.
	 *
	 * @param string $token The access token
	 * @param string $failed Whether the previous attempt failed ('1' to report)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 15, period: 60)]
	public function show(string $token, string $failed = '0'): JSONResponse {
		// A reported prior failure increments the brute-force counter and may
		// delete the link share before we attempt to serve the blob again.
		if ($failed === '1') {
			$this->linkShareService->recordFailedAttempt(token: $token);
		}

		try {
			$linkShare = $this->linkShareService->getByToken(token: $token);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Link not found or expired'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $linkShare->jsonSerializePublic());
	}//end show()

	/**
	 * Phase 2: confirm a successful client-side decryption.
	 *
	 * Atomically increments the usage count, resets failed_attempts, and
	 * auto-deletes the link share when the usage limit is reached.
	 *
	 * @param string $token The access token
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 15, period: 60)]
	public function confirm(string $token): JSONResponse {
		try {
			$linkShare = $this->linkShareService->confirmAccess(token: $token);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Link not found or expired'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(
			data: [
				'usageCount' => $linkShare->getUsageCount(),
				'usageLimit' => $linkShare->getUsageLimit(),
				'remaining' => max(0, ($linkShare->getUsageLimit() - $linkShare->getUsageCount())),
			]
		);
	}//end confirm()
}//end class
