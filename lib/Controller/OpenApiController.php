<?php

/**
 * Keepiq OpenAPI controller
 *
 * Serves the OpenAPI document of the versioned Keepiq API (openapi.json at
 * the app root) at /api/v1/openapi.json, as the NL API Design Rules require
 * (/core/doc-openapi). The document describes routes only; it holds no
 * instance data, so it is public and readable cross-origin.
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

use OCA\Keepiq\Kernel\AppIdentity;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use stdClass;

/**
 * Returns the committed OpenAPI document with the installed app version.
 */
class OpenApiController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest    $request    The current request
	 * @param IAppManager $appManager Locates the app and reads its version
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The OpenAPI document, or 404 when it is not installed or unreadable.
	 *
	 * @return JSONResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(): JSONResponse {
		$document = $this->document();
		$response = new JSONResponse(
			data: ['message' => 'The OpenAPI document is not installed'],
			statusCode: Http::STATUS_NOT_FOUND
		);
		if ($document !== null) {
			$document->info->version = $this->appManager->getAppVersion(appId: AppIdentity::APP_ID);
			$response = new JSONResponse(data: $document);
		}

		$response->addHeader(name: 'Access-Control-Allow-Origin', value: '*');
		return $response;
	}//end show()

	/**
	 * Read openapi.json from the app root.
	 *
	 * @return stdClass|null The document, or null when missing or not an OpenAPI object
	 */
	private function document(): ?stdClass {
		$path = $this->appManager->getAppPath(appId: AppIdentity::APP_ID).'/openapi.json';
		if (is_readable($path) === false) {
			return null;
		}

		$document = json_decode((string)file_get_contents($path), false);
		if ($document instanceof stdClass && ($document->info ?? null) instanceof stdClass) {
			return $document;
		}

		return null;
	}//end document()
}//end class
