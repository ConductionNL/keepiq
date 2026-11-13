<?php

/**
 * Keepiq middleware registrar
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
 *
 * @spec openspec/specs/app-shell/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the request middleware.
 *
 * @spec openspec/specs/app-shell/spec.md
 */
final class MiddlewareRegistrar {

	/**
	 * Register the API contract middleware.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function registerApiContract(IRegistrationContext $context): void {
		// The API contract (version header, deprecation of unversioned paths,
		// problem details). Registered first: Nextcloud runs afterController in
		// reverse order, so it sees the final response of every other middleware.
		$context->registerMiddleware(class: ApiContractMiddleware::class);
	}//end registerApiContract()

	/**
	 * Register the JWT, vault-key-proof, OCS refusal, share-scan and version middleware.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function registerPlatformMiddleware(IRegistrationContext $context): void {
		// The JWT-Bearer middleware for application-authenticated routes.
		// Fires only on ApplicationApiController subclasses; session
		// controllers pass through untouched.
		$context->registerMiddleware(JwtAuthMiddleware::class);

		// The vault-key-proof middleware. Runs for every controller but acts
		// only on methods carrying #[VaultKeyProofRequired]; every other method
		// passes through untouched.
		$context->registerMiddleware(VaultKeyProofMiddleware::class);

		// Keeps a refusal of a Keepiq OCSController visible: Nextcloud's
		// OCSMiddleware rewrites a 403 there into an HTTP 200 OCS envelope, so
		// the controller's own 403 leaves as 428 with an `error` code.
		$context->registerMiddleware(OcsRefusalMiddleware::class);

		// A request that reaches a share row the pending share quarantine scan
		// cannot judge answers 409 share_scan_pending on every route.
		$context->registerMiddleware(ShareScanPendingMiddleware::class);

		// Every API response names the Keepiq version that answered it
		// (NL API Design Rules /core/version-header).
		$context->registerMiddleware(ApiVersionMiddleware::class);
	}//end registerPlatformMiddleware()
}//end class
