<?php

/**
 * Keepiq Application Token Controller
 *
 * Public OAuth-style token endpoint that exchanges a signed JWT
 * assertion (private_key_jwt grant) for a short-lived opaque Bearer
 * access token. The endpoint is anonymous (#[PublicPage]) — security
 * is enforced via signature verification against the registered
 * application's certificate public key.
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

use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Service\JwtAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

/**
 * Public token-exchange endpoint (`POST /api/v1/token`).
 */
class ApplicationTokenController extends Controller {
	/**
	 * Constructor for ApplicationTokenController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param JwtAuthService $service The JWT auth service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private JwtAuthService $service,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Exchange a JWT-Bearer assertion for an opaque access token.
	 *
	 * Accepts an OAuth-style form-or-JSON request body with two
	 * parameters:
	 *
	 * - `grant_type` — must be `urn:ietf:params:oauth:grant-type:jwt-bearer`.
	 * - `assertion`  — the Compact-Serialized JWS signed with the
	 *   application's registered private key.
	 *
	 * Nextcloud's dispatcher binds request parameters to method arguments by
	 * EXACT name, so the camelCase argument `$grantType` never received the
	 * snake_case `grant_type` that RFC 7523 §2.1 — and this app's own
	 * `.well-known/doriath` discovery document — tell every client to send.
	 * `$grantType` was therefore always the empty-string default, the endpoint
	 * answered `400 unsupported_grant_type` to every well-formed request, and
	 * no standards-compliant client could obtain a token at all. Read the
	 * canonical wire name off the request and keep the camelCase argument as a
	 * fallback for callers already written against it.
	 *
	 * @param string $grantType The grant type (camelCase fallback spelling;
	 *                          the canonical wire name is `grant_type`)
	 * @param string $assertion The JWS compact serialization
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'keepiqTokenExchange')]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function exchange(string $grantType = '', string $assertion = ''): JSONResponse {
		$grant = $this->resolveGrantType(fallback: $grantType);

		if ($grant !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
			$response = new JSONResponse(
				data: [
					'error' => 'unsupported_grant_type',
					'error_description' => 'Only urn:ietf:params:oauth:grant-type:jwt-bearer is supported',
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
			$response->throttle(['action' => 'keepiqTokenExchange']);
			return $response;
		}

		if ($assertion === '') {
			$response = new JSONResponse(
				data: [
					'error' => 'invalid_request',
					'error_description' => 'assertion parameter is required',
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
			$response->throttle(['action' => 'keepiqTokenExchange']);
			return $response;
		}

		try {
			$result = $this->service->exchangeAssertion($assertion);
		} catch (RuntimeException $e) {
			// Failed exchange — register a brute-force attempt so repeated
			// invalid assertions are progressively throttled (secret-store-api D7).
			$response = new JSONResponse(
				data: [
					'error' => 'invalid_grant',
					'error_description' => $e->getMessage(),
				],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
			$response->throttle(['action' => 'keepiqTokenExchange']);
			return $response;
		}

		return new JSONResponse(data: $result);
	}//end exchange()

	/**
	 * Resolve the OAuth grant type from the request.
	 *
	 * The canonical wire name is the snake_case `grant_type` (RFC 7523 §2.1).
	 * `$fallback` carries the camelCase spelling that Nextcloud's dispatcher is
	 * able to bind directly, so callers written against either name work.
	 *
	 * @param string $fallback The dispatcher-bound camelCase `grantType` value
	 *
	 * @return string The requested grant type, or an empty string when absent
	 */
	private function resolveGrantType(string $fallback): string {
		$canonical = $this->request->getParam('grant_type');
		if (is_string($canonical) === true && $canonical !== '') {
			return $canonical;
		}

		return $fallback;
	}//end resolveGrantType()
}//end class
