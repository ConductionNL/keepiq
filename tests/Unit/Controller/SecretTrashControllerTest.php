<?php

/**
 * Unit tests for the trash and archive endpoints (vault-trash-and-archive).
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\SecretTrashController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\SecretTrashService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Status mapping of the trash endpoints.
 */
class SecretTrashControllerTest extends TestCase {
	/** @var SecretTrashService&MockObject */
	private SecretTrashService $trash;

	private SecretTrashController $controller;

	/**
	 * Build the controller for alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->trash = $this->createMock(SecretTrashService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new SecretTrashController(
			request: $this->createMock(IRequest::class),
			trashService: $this->trash,
			userSession: $session,
		);
	}//end setUp()

	/**
	 * DELETE moves to the trash and keeps the old status for existing clients.
	 *
	 * @return void
	 */
	public function testDeleteTrashes(): void {
		$this->trash->expects($this->once())->method('trash')->with('s-1', 'alice')->willReturn(new Secret());

		$response = $this->controller->trash('s-1');

		$this->assertSame(['status' => 'deleted', 'trashed' => true], $response->getData());
	}//end testDeleteTrashes()

	/**
	 * Another user's secret answers 403.
	 *
	 * @return void
	 */
	public function testAnotherUsersSecretIsForbidden(): void {
		$this->trash->method('restore')->willThrowException(new ForbiddenException(message: 'no'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->restore('s-1')->getStatus());
	}//end testAnotherUsersSecretIsForbidden()

	/**
	 * Purging a secret that is not in the trash answers 409.
	 *
	 * @return void
	 */
	public function testPurgeOfALiveSecretConflicts(): void {
		$this->trash->method('purge')->willThrowException(new InvalidArgumentException('not in the trash'));

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->purge('s-1')->getStatus());
	}//end testPurgeOfALiveSecretConflicts()

	/**
	 * Archive and unarchive answer the secret.
	 *
	 * @return void
	 */
	public function testArchiveAnswersTheSecret(): void {
		$secret = new Secret();
		$secret->setId('s-1');
		$this->trash->method('archive')->willReturn($secret);
		$this->trash->method('unarchive')->willReturn($secret);

		$this->assertSame(Http::STATUS_OK, $this->controller->archive('s-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controller->unarchive('s-1')->getStatus());
	}//end testArchiveAnswersTheSecret()
}//end class
