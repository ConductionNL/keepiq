<?php

/**
 * Unit tests for the generator-failure path of KeyGeneratorController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\KeyGeneratorController;
use OCA\Keepiq\Kernel\Contracts\KeyGeneratorServiceInterface;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A generator that cannot satisfy the request answers 422 with its reason.
 */
#[CoversClass(KeyGeneratorController::class)]
#[UsesClass(MessageResponse::class)]
class KeyGeneratorControllerUnsatisfiableTest extends TestCase {

	/**
	 * A runtime failure (e.g. a regex no candidate matches) is a 422 with the message.
	 *
	 * @return void
	 */
	public function testRuntimeFailureIsUnprocessable(): void {
		$user = $this->createStub(IUser::class);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$generator = $this->createMock(KeyGeneratorServiceInterface::class);
		$generator->expects($this->once())
			->method('generate')
			->with(8, false, 'abc', '^[0-9]+$')
			->willThrowException(new RuntimeException('No key matched the pattern'));

		$controller = new KeyGeneratorController(
			request: $this->createStub(IRequest::class),
			keyGenerator: $generator,
			userSession: $session,
		);

		$response = $controller->generate(length: 8, includeSpecialCharacters: false, excludedCharacters: 'abc', regex: '^[0-9]+$');

		$this->assertInstanceOf(MessageResponse::class, $response);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'No key matched the pattern'], $response->getData());
	}//end testRuntimeFailureIsUnprocessable()
}//end class
