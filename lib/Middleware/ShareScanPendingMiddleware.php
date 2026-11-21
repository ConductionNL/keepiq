<?php

/**
 * Keepiq share-scan-pending middleware
 *
 * Answers a request refused because it reaches a share row the pending share
 * quarantine scan cannot judge with `409` and `error: share_scan_pending`, on
 * every Keepiq controller, also on a route whose controller does not catch
 * the refusal itself. The body carries only the refusal's own fixed text.
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

use Exception;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * Maps the share-scan-pending refusal to its 409 on every route.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
class ShareScanPendingMiddleware extends Middleware {
	/**
	 * Answer the share-scan-pending refusal; rethrow anything else.
	 *
	 * @param mixed     $controller The controller that ran
	 * @param string    $methodName The method that ran
	 * @param Exception $exception  The exception it threw
	 *
	 * @return Response
	 *
	 * @throws Exception Any exception but the share-scan-pending refusal
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Middleware::afterException()'s.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if (($exception instanceof ShareScanPendingException) === false) {
			throw $exception;
		}

		return new JSONResponse(
			data: ['error' => 'share_scan_pending', 'message' => $exception->getMessage()],
			statusCode: Http::STATUS_CONFLICT
		);
	}//end afterException()
}//end class
