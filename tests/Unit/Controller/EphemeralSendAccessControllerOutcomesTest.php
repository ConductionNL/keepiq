<?php

/**
 * Outcome tests for EphemeralSendAccessController.
 *
 * Every public action answers the service's payload for the token, and an
 * unknown, spent or expired send answers one uniform 404.
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

use OCA\Keepiq\Controller\EphemeralSendAccessController;
use OCA\Keepiq\Service\EphemeralSendService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\EphemeralSendAccessController
 */
class EphemeralSendAccessControllerOutcomesTest extends TestCase {

	/**
	 * The mocked send service.
	 *
	 * @var EphemeralSendService&MockObject
	 */
	private EphemeralSendService&MockObject $service;

	/**
	 * The controller under test.
	 *
	 * @var EphemeralSendAccessController
	 */
	private EphemeralSendAccessController $controller;

	/**
	 * Build the controller over a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(EphemeralSendService::class);
		$this->controller = new EphemeralSendAccessController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
		);
	}//end setUp()

	/**
	 * Each action calls its own service method with the token and answers
	 * its payload.
	 *
	 * @return void
	 */
	public function testEachActionAnswersItsServicePayload(): void {
		$this->service->expects($this->once())->method('peek')->with('tok')->willReturn(['step' => 'peek']);
		$this->service->expects($this->once())->method('access')->with('tok')->willReturn(['step' => 'access']);
		$this->service->expects($this->once())->method('confirmView')->with('tok')->willReturn(['step' => 'confirm']);
		$this->service->expects($this->once())->method('reportFailure')->with('tok')->willReturn(['step' => 'failure']);

		$this->assertSame(['step' => 'peek'], $this->controller->peek(token: 'tok')->getData());
		$this->assertSame(['step' => 'access'], $this->controller->access(token: 'tok')->getData());
		$this->assertSame(['step' => 'confirm'], $this->controller->confirm(token: 'tok')->getData());
		$this->assertSame(['step' => 'failure'], $this->controller->failure(token: 'tok')->getData());
	}//end testEachActionAnswersItsServicePayload()

	/**
	 * Every action answers the same 404 for a send that does not exist.
	 *
	 * @return void
	 */
	public function testEveryActionAnswersTheSame404ForAnUnknownSend(): void {
		foreach (['peek', 'access', 'confirmView', 'reportFailure'] as $method) {
			$this->service->method($method)->willThrowException(new DoesNotExistException('gone'));
		}

		foreach ([
			$this->controller->peek(token: 'tok'),
			$this->controller->access(token: 'tok'),
			$this->controller->confirm(token: 'tok'),
			$this->controller->failure(token: 'tok'),
		] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['message' => 'Send not found'], $response->getData());
		}
	}//end testEveryActionAnswersTheSame404ForAnUnknownSend()
}//end class
