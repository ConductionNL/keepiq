<?php

/**
 * Keepiq Link Share Access Controller
 *
 * Public (unauthenticated) API controller for the two-phase link share
 * access protocol used by the external recipient's browser.
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
use OCA\Keepiq\Service\LinkShareService;
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
	 * Each fetch counts as one use and one attempt before the blob is
	 * returned. Every error case returns a uniform 404 with a generic
	 * message to prevent token enumeration.
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
	public function show(string $token): JSONResponse {
		try {
			$payload = $this->linkShareService->serve(token: $token);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Link not found or expired'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $payload);
	}//end show()

	/**
	 * Phase 2: confirm a successful client-side decryption.
	 *
	 * Resets the attempts; the use was counted when the blob was served.
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
