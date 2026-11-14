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
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\SecretTrashService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\StaleWriteException;

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
	 * A restore answers the secret; a restored copy from another
	 * organisation adds what became of its share, so the browser can say
	 * that it ended.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
	 */
	public function testRestoreSaysWhatBecameOfAFederatedShare(): void {
		$secret = new Secret();
		$secret->setId('s-1');
		$this->trash->method('restore')->willReturnOnConsecutiveCalls(
			['secret' => $secret, 'federatedShare' => 'ended'],
			['secret' => $secret, 'federatedShare' => null],
		);

		$copy = $this->controller->restore('s-1')->getData();
		$this->assertSame('ended', $copy['federatedShare']);
		$this->assertSame('s-1', $copy['id']);

		$own = $this->controller->restore('s-1')->getData();
		$this->assertArrayNotHasKey('federatedShare', $own);
		$this->assertSame($secret->jsonSerialize(), $own);
	}//end testRestoreSaysWhatBecameOfAFederatedShare()

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
	 * A delete refused because a share of the secret waits for the share
	 * quarantine scan answers 409 with the error code a share revoke gets.
	 *
	 * @return void
	 */
	public function testADeleteThatWaitsForTheQuarantineScanIs409(): void {
		$this->trash->method('trash')->willThrowException(new ShareScanPendingException('A share of this secret waits for the share quarantine scan'));

		$response = $this->controller->trash('s-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['error' => 'share_scan_pending', 'message' => 'A share of this secret waits for the share quarantine scan'],
			$response->getData()
		);
	}//end testADeleteThatWaitsForTheQuarantineScanIs409()

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

	/**
	 * A purge answers that the secret is purged, for the signed-in owner.
	 *
	 * @return void
	 */
	public function testPurgeAnswersPurged(): void {
		$this->trash->expects($this->once())->method('purge')->with('s-1', 'alice');

		$response = $this->controller->purge('s-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'purged'], $response->getData());
	}//end testPurgeAnswersPurged()

	/**
	 * Without a user nothing is asked of the service and the answer is 401.
	 *
	 * @return void
	 */
	public function testWithoutAUserEveryActionIs401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->trash->expects($this->never())->method($this->anything());
		$controller = new SecretTrashController(
			request: $this->createMock(IRequest::class),
			trashService: $this->trash,
			userSession: $session,
		);

		foreach (['trash', 'restore', 'purge', 'archive', 'unarchive'] as $action) {
			$response = $controller->$action('s-1');
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $action);
			$this->assertSame(['message' => 'Unauthorized'], $response->getData(), $action);
		}
	}//end testWithoutAUserEveryActionIs401()

	/**
	 * A secret that does not exist answers 404 with the service's message.
	 *
	 * @return void
	 */
	public function testAnUnknownSecretIs404(): void {
		$this->trash->method('archive')->willThrowException(new NotFoundException('Secret not found'));

		$response = $this->controller->archive('s-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Secret not found'], $response->getData());
	}//end testAnUnknownSecretIs404()

	/**
	 * A secret that moved on meanwhile answers 409 with the current row.
	 *
	 * @return void
	 */
	public function testAStaleSecretIs409WithTheCurrentRow(): void {
		$current = new Secret();
		$current->setId('s-1');
		$current->setName('Router');
		$this->trash->method('unarchive')->willThrowException(new StaleWriteException(current: $current));

		$response = $this->controller->unarchive('s-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame($current->jsonSerialize(), $response->getData()['current']);
		$this->assertArrayHasKey('message', $response->getData());
	}//end testAStaleSecretIs409WithTheCurrentRow()
}//end class
