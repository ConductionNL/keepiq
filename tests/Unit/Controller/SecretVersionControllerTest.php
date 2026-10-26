<?php

/**
 * Unit tests for SecretVersionController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\SecretVersionController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The restore route passes the base on and answers a stale one like
 * `PUT /secrets/{id}` does.
 */
class SecretVersionControllerTest extends TestCase {
	private SecretVersionService&MockObject $versionService;

	private SecretVersionController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->versionService = $this->createMock(SecretVersionService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new SecretVersionController(
			request: $this->createMock(IRequest::class),
			versionService: $this->versionService,
			userSession: $session
		);
	}

	private function head(): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setKey('ENCRYPTED');
		return $secret;
	}

	public function testAStaleBaseAnswers409WithTheCurrentRow(): void {
		$this->versionService->expects($this->once())->method('restore')
			->with('v-1', 'alice', '2026-10-01T09:00:00+00:00')
			->willThrowException(new StaleWriteException(current: $this->head()));

		$response = $this->controller->restore(id: 'v-1', baseUpdatedAt: '2026-10-01T09:00:00+00:00');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This secret changed since your copy was made', $response->getData()['message']);
		$this->assertSame('ENCRYPTED', $response->getData()['current']['key']);
	}

	public function testWithoutABaseTheRestoreIsUnchecked(): void {
		$this->versionService->expects($this->once())->method('restore')
			->with('v-1', 'alice', null)
			->willReturn($this->head());

		$this->assertSame(Http::STATUS_OK, $this->controller->restore(id: 'v-1')->getStatus());
	}

	public function testARestoreOfAViewOnlyCopyAnswers403(): void {
		$this->versionService->method('restore')
			->willThrowException(new ViewOnlyCopyException());

		$response = $this->controller->restore(id: 'v-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => Secret::VIEW_ONLY_REFUSAL, 'code' => 'view_only'], $response->getData());
	}

	public function testARestoreOfACopyWithoutASourceAnswers404(): void {
		$this->versionService->method('restore')
			->willThrowException(new NotFoundException(message: 'Secret not found'));

		$response = $this->controller->restore(id: 'v-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Secret not found'], $response->getData());
	}
}
