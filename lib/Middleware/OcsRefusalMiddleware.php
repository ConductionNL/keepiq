<?php

/**
 * Keepiq OCS refusal middleware
 *
 * Keeps a refusal on a Keepiq OCSController route visible to the client.
 * Nextcloud's OCSMiddleware rewrites every 401 and 403 response of an
 * OCSController into an OCS v1 envelope, which on an /index.php/apps route is
 * HTTP 200 without the body's machine-readable code. Measured live on
 * Nextcloud 35 (4 Oct 2026): 400, 409, 422 and 428 pass untouched. So a 403 on
 * a Keepiq OCSController route leaves here as 428 Precondition Required,
 * with its body kept and an `error` code added: the policy `code` when it has
 * one, otherwise `forbidden`.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCSController;

/**
 * Turns every 403 of a Keepiq OCSController into a 428 the OCS layer leaves alone.
 *
 * Nextcloud runs every app middleware's afterController before OCSMiddleware's,
 * so the status is changed before the rewrite looks at it. That holds for a
 * refusal a Keepiq controller returns and for one a middleware returns,
 * Nextcloud's own included (not an admin, password confirmation required):
 * Nextcloud only asks the middlewares that already ran beforeController to
 * handle an exception, but it runs afterController on all of them. So every
 * refusal on a Keepiq OCS route reaches the client as 428 with an `error`;
 * before, Nextcloud's own ones arrived as an HTTP 200 envelope too.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
class OcsRefusalMiddleware extends Middleware {
	/**
	 * The status every refusal leaves with.
	 */
	public const REFUSAL_STATUS = Http::STATUS_PRECONDITION_REQUIRED;

	/**
	 * Re-status a 403 on a Keepiq OCSController as 428 with an `error` code.
	 *
	 * @param mixed    $controller The controller
	 * @param string   $methodName The method
	 * @param Response $response   The controller's response
	 *
	 * @return Response
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $methodName is mandated by
	 *   OCP\AppFramework\Middleware::afterController().
	 *
	 * @spec openspec/changes/archive/2026-10-04-harden-vault-key-material-guards/tasks.md#task-6.5
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if (($controller instanceof OCSController) === false
			|| ($response instanceof JSONResponse) === false
			|| $response->getStatus() !== Http::STATUS_FORBIDDEN
		) {
			return $response;
		}

		$data = $response->getData();
		if (is_array($data) === false) {
			$data = ['message' => (string)$data];
		}

		if (isset($data['error']) === false) {
			$data['error'] = $data['code'] ?? 'forbidden';
		}

		$response->setData($data);
		$response->setStatus(self::REFUSAL_STATUS);
		return $response;
	}//end afterController()
}//end class
