<?php

/**
 * Unit tests for LinkShareController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Controller\LinkShareController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\LinkShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LinkShareController.
 */
class LinkShareControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var LinkShareController
	 */
	private LinkShareController $controller;

	/**
	 * The mocked link share service.
	 *
	 * @var LinkShareService&MockObject
	 */
	private LinkShareService&MockObject $linkShareService;

	/**
	 * The mocked suite service.
	 *
	 * @var EncryptionSuiteService&MockObject
	 */
	private EncryptionSuiteService&MockObject $suiteService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(originalClassName: IRequest::class);
		$this->linkShareService = $this->createMock(originalClassName: LinkShareService::class);
		$this->suiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/keepiq/public');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example.com' . $path
		);

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new LinkShareController(
			request: $request,
			linkShareService: $this->linkShareService,
			suiteService: $this->suiteService,
			userSession: $userSession,
			urlGenerator: $urlGenerator,
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
		$share->setToken('abcd1234');
		$share->setEncryptedSecretSnapshot('blob');
		$share->setArgon2idSalt('salt');
		$share->setEncryptionSuiteId('suite-1');
		$share->setUsageLimit(3);
		$share->setUsageCount(0);
		$share->setCreatedBy('alice');
		$share->setCreatedAt(new DateTime());

		return $share;
	}//end makeShare()

	/**
	 * Build an active EncryptionSuite for the owner.
	 *
	 * @return EncryptionSuite
	 */
	private function makeSuite(): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setStatus('active');

		return $suite;
	}//end makeSuite()

	/**
	 * Test index returns metadata without the blob.
	 *
	 * @return void
	 */
	public function testIndexReturnsShares(): void {
		$this->linkShareService->method('listBySecret')
			->with('secret-1', 'alice')
			->willReturn([$this->makeShare()]);

		$response = $this->controller->index('secret-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $data);
		$this->assertArrayNotHasKey('encryptedSecretSnapshot', $data[0]);
		$this->assertArrayNotHasKey('argon2idSalt', $data[0]);
	}//end testIndexReturnsShares()

	/**
	 * Test create returns 201 with token and link URL, no blob echoed.
	 *
	 * @return void
	 */
	public function testCreateReturnsCreatedWithLinkUrl(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->makeSuite());
		$this->linkShareService->method('create')->willReturn($this->makeShare());

		$response = $this->controller->create(
			secretId: 'secret-1',
			encryptedSecretSnapshot: 'blob',
			argon2idSalt: 'salt',
			usageLimit: 3,
			expiresAt: null
		);
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('abcd1234', $data['token']);
		$this->assertArrayHasKey('linkUrl', $data);
		// The PATH form on the anonymous shell — not the retired hash form,
		// which the createWebHistory router never reads (recipients got the
		// lock screen), and not the authenticated path, which answers 401.
		$this->assertSame(
			'https://cloud.example.com/apps/keepiq/public/share/link/abcd1234',
			$data['linkUrl']
		);
		$this->assertArrayNotHasKey('encryptedSecretSnapshot', $data);
	}//end testCreateReturnsCreatedWithLinkUrl()

	/**
	 * Test create returns 400 when the usage limit is invalid.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidUsageLimit(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->makeSuite());
		$this->linkShareService->method('create')
			->willThrowException(new InvalidArgumentException('Usage limit must be between 1 and 10'));

		$response = $this->controller->create(
			secretId: 'secret-1',
			encryptedSecretSnapshot: 'blob',
			argon2idSalt: 'salt',
			usageLimit: 11,
			expiresAt: null
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateRejectsInvalidUsageLimit()

	/**
	 * A caller who may not re-share the secret gets 404, like a missing
	 * secret (keepiq#214).
	 *
	 * @return void
	 */
	public function testCreateAnswers404WhenTheCallerMayNotReshare(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->makeSuite());
		$this->linkShareService->method('create')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Secret not found'));

		$response = $this->controller->create(
			secretId: 'secret-1',
			encryptedSecretSnapshot: 'blob',
			argon2idSalt: 'salt',
			usageLimit: 1,
			expiresAt: null
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testCreateAnswers404WhenTheCallerMayNotReshare()

	/**
	 * A link share of a secret whose share row waits for the share
	 * quarantine scan answers 409 with its own error code.
	 *
	 * @return void
	 */
	public function testCreateAnswersScanPendingWithItsOwnCode(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->makeSuite());
		$this->linkShareService->method('create')
			->willThrowException(new ShareScanPendingException('This secret waits for the share quarantine scan'));

		$response = $this->controller->create(
			secretId: 'secret-1',
			encryptedSecretSnapshot: 'blob',
			argon2idSalt: 'salt',
			usageLimit: 1,
			expiresAt: null
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('share_scan_pending', $response->getData()['error']);
	}//end testCreateAnswersScanPendingWithItsOwnCode()

	/**
	 * Test create returns 400 when the expiry timestamp is malformed.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidExpiry(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->makeSuite());

		$response = $this->controller->create(
			secretId: 'secret-1',
			encryptedSecretSnapshot: 'blob',
			argon2idSalt: 'salt',
			usageLimit: 1,
			expiresAt: 'not-a-date'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateRejectsInvalidExpiry()

	/**
	 * Test destroy returns 200 on a successful revoke.
	 *
	 * @return void
	 */
	public function testDestroySucceeds(): void {
		$this->linkShareService->expects($this->once())
			->method('delete')
			->with('ls-1', 'alice');

		$response = $this->controller->destroy('ls-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDestroySucceeds()

	/**
	 * Test destroy returns 403 when the requester is not the owner.
	 *
	 * @return void
	 */
	public function testDestroyNonOwnerForbidden(): void {
		$this->linkShareService->method('delete')
			->willThrowException(new InvalidArgumentException('Access denied'));

		$response = $this->controller->destroy('ls-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testDestroyNonOwnerForbidden()

	/**
	 * Test destroy returns 404 when the link share does not exist.
	 *
	 * @return void
	 */
	public function testDestroyNotFound(): void {
		$this->linkShareService->method('delete')
			->willThrowException(new RuntimeException('Link share not found'));

		$response = $this->controller->destroy('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDestroyNotFound()

	/**
	 * Create hands every field to the service, and answers 400 without an
	 * active suite and 403 for a copy from another organisation.
	 *
	 * @return void
	 */
	public function testCreatePassesTheFieldsAndMapsTheSuiteAndForbiddenRefusals(): void {
		$this->suiteService->method('getActiveSuite')->willReturnOnConsecutiveCalls(
			$this->throwException(new \OCP\AppFramework\Db\DoesNotExistException('none')),
			$this->makeSuite(),
			$this->makeSuite()
		);
		$passed = [];
		$this->linkShareService->method('create')->willReturnCallback(
			function (...$args) use (&$passed): LinkShare {
				$passed[] = $args;
				if (count($passed) === 1) {
					return $this->makeShare();
				}

				throw new \OCA\Keepiq\Exception\ForbiddenException('A copy from another organisation cannot be shared');
			}
		);

		$noSuite = $this->controller->create(secretId: 'secret-1', encryptedSecretSnapshot: 'blob', argon2idSalt: 'salt');
		$created = $this->controller->create(secretId: 'secret-2', encryptedSecretSnapshot: 'blob2', argon2idSalt: 'salt2', usageLimit: 4, expiresAt: '2099-01-02T03:04:05+00:00');
		$forbidden = $this->controller->create(secretId: 'secret-3', encryptedSecretSnapshot: 'blob3', argon2idSalt: 'salt3');

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['message' => 'No active encryption suite for the current user']], [$noSuite->getStatus(), $noSuite->getData()]);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame([Http::STATUS_FORBIDDEN, ['message' => 'A copy from another organisation cannot be shared']], [$forbidden->getStatus(), $forbidden->getData()]);
		$this->assertSame($this->describedCreateCalls(calls: $passed), [
			['secret-2', 'blob2', 'salt2', 'suite-1', 4, '2099-01-02T03:04:05+00:00', 'alice'],
			['secret-3', 'blob3', 'salt3', 'suite-1', 1, null, 'alice'],
		]);
	}//end testCreatePassesTheFieldsAndMapsTheSuiteAndForbiddenRefusals()

	/**
	 * The fields each service create call received, in order.
	 *
	 * @param array<int,array<int,mixed>> $calls The recorded calls
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function describedCreateCalls(array $calls): array {
		return array_map(
			static function (array $args): array {
				[$secretId, $snapshot, $salt, $suiteId, $limit, $expiresAt, $userId] = $args;
				return [$secretId, $snapshot, $salt, $suiteId, $limit, $expiresAt?->format(DATE_ATOM), $userId];
			},
			$calls
		);
	}//end describedCreateCalls()
}//end class
