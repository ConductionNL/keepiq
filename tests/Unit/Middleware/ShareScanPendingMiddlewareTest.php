<?php

/**
 * A request refused because it reaches a share row the pending quarantine
 * scan cannot judge answers 409 share_scan_pending on every route; any
 * other exception passes on untouched.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware
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

namespace OCA\Keepiq\Tests\Unit\Middleware;

use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Middleware\ShareScanPendingMiddleware;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Tests the share-scan-pending middleware.
 */
class ShareScanPendingMiddlewareTest extends TestCase {

	/**
	 * The refusal becomes a 409 with its own error code and fixed text.
	 *
	 * @return void
	 */
	public function testTheRefusalAnswers409WithItsOwnCode(): void {
		$response = (new ShareScanPendingMiddleware())->afterException(
			controller: new \stdClass(),
			methodName: 'create',
			exception: new ShareScanPendingException('This secret waits for the share quarantine scan')
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['error' => 'share_scan_pending', 'message' => 'This secret waits for the share quarantine scan'],
			$response->getData()
		);
	}//end testTheRefusalAnswers409WithItsOwnCode()

	/**
	 * Any other exception, also another conflict, is not touched.
	 *
	 * @return void
	 */
	public function testAnyOtherExceptionPassesOn(): void {
		$other = new ConflictException('quarantined');

		$this->expectExceptionObject($other);
		(new ShareScanPendingMiddleware())->afterException(controller: new \stdClass(), methodName: 'create', exception: $other);
	}//end testAnyOtherExceptionPassesOn()
}//end class
