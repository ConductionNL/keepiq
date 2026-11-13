<?php

/**
 * Keepiq API version middleware
 *
 * Adds the full app version as an `API-Version` header to every response of
 * a Keepiq API route, so a client can tell which build answered (NL API
 * Design Rules, /core/version-header). The header is additive: status, body
 * and every other header are left as they are.
 *
 * @category Middleware
 * @package  OCA\Keepiq\Middleware
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

namespace OCA\Keepiq\Middleware;

use OCA\Keepiq\Kernel\AppIdentity;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Sets `API-Version` on responses of /api/ routes; every other route passes untouched.
 */
class ApiVersionMiddleware extends Middleware {
	/**
	 * The response header that carries the version.
	 */
	public const HEADER = 'API-Version';

	/**
	 * The path segment every Keepiq API route carries.
	 */
	private const API_PATH_MARKER = '/api/';

	/**
	 * Constructor.
	 *
	 * @param IRequest    $request    The current request
	 * @param IAppManager $appManager Reads the installed Keepiq version
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Add the API-Version header to a response of an API route.
	 *
	 * @param mixed    $controller The controller
	 * @param string   $methodName The method
	 * @param Response $response   The controller's response
	 *
	 * @return Response
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are mandated by
	 *   OCP\AppFramework\Middleware::afterController().
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		$path = $this->request->getPathInfo();
		if (is_string($path) === false || str_contains($path, self::API_PATH_MARKER) === false) {
			return $response;
		}

		$response->addHeader(name: self::HEADER, value: $this->appManager->getAppVersion(appId: AppIdentity::APP_ID));
		return $response;
	}//end afterController()
}//end class
