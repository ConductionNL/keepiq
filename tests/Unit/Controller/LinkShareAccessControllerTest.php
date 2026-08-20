<?php

/**
 * Unit tests for LinkShareAccessController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use DateTime;
use OCA\Doriath\Controller\LinkShareAccessController;
use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Service\LinkShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LinkShareAccessController.
 */
class LinkShareAccessControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var LinkShareAccessController
	 */
	private LinkShareAccessController $controller;

	/**
	 * The mocked link share service.
	 *
	 * @var LinkShareService&MockObject
	 */
	private LinkShareService&MockObject $linkShareService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(originalClassName: IRequest::class);
		$this->linkShareService = $this->createMock(originalClassName: LinkShareService::class);

		$this->controller = new LinkShareAccessController(
			request: $request,
			linkShareService: $this->linkShareService,
		);
	}//end setUp()

	/**
	 * Build a LinkShare entity for assertions.
	 *
	 * @return LinkShare
	 */
	private function makeShare(): LinkShare {
		$share = new LinkShare();
		$share->setId('ls-1');
		$share->setSecretId('secret-1');
		$share->setToken('tok-1');
		$share->setEncryptedSecretSnapshot('the-blob');
		$share->setArgon2idSalt('the-salt');
		$share->setUsageLimit(3);
		$share->setUsageCount(1);
		$share->setCreatedBy('alice');
		$share->setCreatedAt(new DateTime());

		return $share;
	}//end makeShare()

	/**
	 * Test Phase 1 returns the blob and salt for a valid token.
	 *
	 * @return void
	 */
	public function testShowReturnsBlobForValidToken(): void {
		$this->linkShareService->method('getByToken')->with('tok-1')->willReturn($this->makeShare());

		$response = $this->controller->show('tok-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('the-blob', $data['encryptedSecretSnapshot']);
		$this->assertSame('the-salt', $data['argon2idSalt']);
		// Public payload must never leak owner identity.
		$this->assertArrayNotHasKey('createdBy', $data);
	}//end testShowReturnsBlobForValidToken()

	/**
	 * Test Phase 1 returns a uniform 404 for an invalid token.
	 *
	 * @return void
	 */
	public function testShowReturnsNotFoundForInvalidToken(): void {
		$this->linkShareService->method('getByToken')
			->willThrowException(new RuntimeException('Link not found or expired'));

		$response = $this->controller->show('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Link not found or expired', $response->getData()['message']);
	}//end testShowReturnsNotFoundForInvalidToken()

	/**
	 * Test Phase 1 records a failed attempt when the browser reports failure.
	 *
	 * @return void
	 */
	public function testShowRecordsFailedAttemptWhenReported(): void {
		$this->linkShareService->expects($this->once())
			->method('recordFailedAttempt')
			->with('tok-1');
		$this->linkShareService->method('getByToken')->willReturn($this->makeShare());

		$response = $this->controller->show('tok-1', '1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testShowRecordsFailedAttemptWhenReported()

	/**
	 * Test Phase 1 does NOT record a failed attempt on the first request.
	 *
	 * @return void
	 */
	public function testShowDoesNotRecordFailureOnFirstRequest(): void {
		$this->linkShareService->expects($this->never())->method('recordFailedAttempt');
		$this->linkShareService->method('getByToken')->willReturn($this->makeShare());

		$this->controller->show('tok-1', '0');
	}//end testShowDoesNotRecordFailureOnFirstRequest()

	/**
	 * Test Phase 2 confirm increments usage and returns remaining count.
	 *
	 * @return void
	 */
	public function testConfirmReturnsUsage(): void {
		$share = $this->makeShare();
		$share->setUsageCount(2);
		$this->linkShareService->method('confirmAccess')->with('tok-1')->willReturn($share);

		$response = $this->controller->confirm('tok-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $data['usageCount']);
		$this->assertSame(3, $data['usageLimit']);
		$this->assertSame(1, $data['remaining']);
	}//end testConfirmReturnsUsage()

	/**
	 * Test Phase 2 confirm returns 404 when the token is invalid or exhausted.
	 *
	 * @return void
	 */
	public function testConfirmReturnsNotFound(): void {
		$this->linkShareService->method('confirmAccess')
			->willThrowException(new RuntimeException('Link not found or expired'));

		$response = $this->controller->confirm('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testConfirmReturnsNotFound()
}//end class
