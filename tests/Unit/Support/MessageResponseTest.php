<?php

/**
 * Unit tests for MessageResponse.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Support
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

namespace OCA\Keepiq\Tests\Unit\Support;

use InvalidArgumentException;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for MessageResponse.
 *
 * @covers \OCA\Keepiq\Support\MessageResponse
 */
class MessageResponseTest extends TestCase {

	/**
	 * The response is the JSON the controllers built inline: the message under
	 * `message` and the chosen status.
	 *
	 * @return void
	 */
	public function testCarriesTheMessageAndStatus(): void {
		$expected = new JSONResponse(
			data: ['message' => 'Bad folder'],
			statusCode: Http::STATUS_BAD_REQUEST
		);

		$response = new MessageResponse(error: new InvalidArgumentException('Bad folder'), status: Http::STATUS_BAD_REQUEST);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame($expected->getData(), $response->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCarriesTheMessageAndStatus()

	/**
	 * Another error type and status, and an empty message, pass through as is.
	 *
	 * @return void
	 */
	public function testPassesAnyErrorAndStatusThrough(): void {
		$response = new MessageResponse(error: new RuntimeException(''), status: Http::STATUS_FORBIDDEN);

		$this->assertSame(['message' => ''], $response->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPassesAnyErrorAndStatusThrough()
}//end class
