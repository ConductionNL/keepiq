<?php

/**
 * Outcome tests for SecretVersionController.
 *
 * Listing, showing and restoring versions refuse an anonymous caller with
 * 401, act for the SESSION user, and map each service refusal to its status.
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
use OCA\Keepiq\Controller\SecretVersionController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PolicyViolationException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\SecretVersionController
 * @uses   \OCA\Keepiq\Db\SecretVersion
 * @uses   \OCA\Keepiq\Exception\ForbiddenException
 * @uses   \OCA\Keepiq\Exception\PolicyViolationException
 * @uses   \OCA\Keepiq\Exception\StaleWriteException
 * @uses   \OCA\Keepiq\Support\MessageResponse
 */
class SecretVersionControllerOutcomesTest extends TestCase {

	/**
	 * The mocked version service.
	 *
	 * @var SecretVersionService&MockObject
	 */
	private SecretVersionService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(SecretVersionService::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return SecretVersionController
	 */
	private function controller(): SecretVersionController {
		return new SecretVersionController(
			request: $this->createMock(IRequest::class),
			versionService: $this->service,
			userSession: $this->session,
		);
	}//end controller()

	/**
	 * Sign in as the given user.
	 *
	 * @param string $uid The user id
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A version with the given name and ciphertext key.
	 *
	 * @param string $name The version name
	 * @param string $key  The ciphertext key blob
	 *
	 * @return SecretVersion
	 */
	private function version(string $name, string $key): SecretVersion {
		$version = new SecretVersion();
		$version->setSecretId('s-1');
		$version->setName($name);
		$version->setKey($key);
		return $version;
	}//end version()

	/**
	 * Every action refuses an anonymous caller with 401 without touching the
	 * service.
	 *
	 * @return void
	 */
	public function testEveryActionRefusesAnAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method($this->anything());

		foreach ([
			$this->controller()->index(secretId: 's-1'),
			$this->controller()->show(id: 'v-1'),
			$this->controller()->restore(id: 'v-1'),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['message' => 'Unauthorized'], $response->getData());
		}
	}//end testEveryActionRefusesAnAnonymousCaller()

	/**
	 * The index lists the session user's versions WITHOUT their blobs.
	 *
	 * @return void
	 */
	public function testIndexListsVersionsWithoutBlobs(): void {
		$this->signIn('alice');
		$this->service->expects($this->once())->method('list')
			->with('s-1', 'alice')
			->willReturn([$this->version('first', 'cipher-1'), $this->version('second', 'cipher-2')]);

		$response = $this->controller()->index(secretId: 's-1');

		$data = $response->getData();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $data);
		$this->assertSame('first', $data[0]['name']);
		$this->assertSame('second', $data[1]['name']);
		$this->assertArrayNotHasKey('key', $data[0]);
	}//end testIndexListsVersionsWithoutBlobs()

	/**
	 * Show answers the version INCLUDING its blobs.
	 *
	 * @return void
	 */
	public function testShowAnswersTheVersionWithItsBlobs(): void {
		$this->signIn('alice');
		$this->service->expects($this->once())->method('getVersion')
			->with('v-1', 'alice')
			->willReturn($this->version('first', 'cipher-1'));

		$response = $this->controller()->show(id: 'v-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('cipher-1', $response->getData()['key']);
		$this->assertSame('first', $response->getData()['name']);
	}//end testShowAnswersTheVersionWithItsBlobs()

	/**
	 * A locked secret's version answers 403; any other refusal 404.
	 *
	 * @return void
	 */
	public function testShowMapsALockedSecretTo403AndOtherRefusalsTo404(): void {
		$this->signIn('alice');
		$this->service->method('getVersion')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('Secret is locked')),
			$this->throwException(new InvalidArgumentException('Version not found')),
		);

		$locked = $this->controller()->show(id: 'v-1');
		$missing = $this->controller()->show(id: 'v-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $locked->getStatus());
		$this->assertSame(['message' => 'Secret is locked'], $locked->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Version not found'], $missing->getData());
	}//end testShowMapsALockedSecretTo403AndOtherRefusalsTo404()

	/**
	 * A restore passes the base timestamp and answers the restored secret.
	 *
	 * @return void
	 */
	public function testRestorePassesTheBaseAndAnswersTheSecret(): void {
		$this->signIn('alice');
		$secret = $this->createMock(Secret::class);
		$secret->method('jsonSerialize')->willReturn(['id' => 7, 'name' => 'restored']);
		$this->service->expects($this->once())->method('restore')
			->with('v-1', 'alice', '2026-01-01T00:00:00+00:00')
			->willReturn($secret);

		$response = $this->controller()->restore(id: 'v-1', baseUpdatedAt: '2026-01-01T00:00:00+00:00');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 7, 'name' => 'restored'], $response->getData());
	}//end testRestorePassesTheBaseAndAnswersTheSecret()

	/**
	 * A stale restore answers 409 with the current secret beside the message.
	 *
	 * @return void
	 */
	public function testAStaleRestoreAnswers409WithTheCurrentSecret(): void {
		$this->signIn('alice');
		$current = $this->createMock(Secret::class);
		$current->method('jsonSerialize')->willReturn(['id' => 7, 'name' => 'newer']);
		$this->service->method('restore')->willThrowException(new StaleWriteException(current: $current));

		$response = $this->controller()->restore(id: 'v-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['message' => 'This secret changed since your copy was made', 'current' => ['id' => 7, 'name' => 'newer']],
			$response->getData()
		);
	}//end testAStaleRestoreAnswers409WithTheCurrentSecret()

	/**
	 * A policy refusal carries its code; a plain refusal does not.
	 *
	 * @return void
	 */
	public function testAForbiddenRestoreCarriesThePolicyCodeOnlyWhenThereIsOne(): void {
		$this->signIn('alice');
		$this->service->method('restore')->willReturnOnConsecutiveCalls(
			$this->throwException(new PolicyViolationException(policyCode: 'min_length', message: 'Too short')),
			$this->throwException(new ForbiddenException('Not allowed')),
		);

		$policy = $this->controller()->restore(id: 'v-1');
		$plain = $this->controller()->restore(id: 'v-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $policy->getStatus());
		$this->assertSame(['message' => 'Too short', 'code' => 'min_length'], $policy->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $plain->getStatus());
		$this->assertSame(['message' => 'Not allowed'], $plain->getData());
	}//end testAForbiddenRestoreCarriesThePolicyCodeOnlyWhenThereIsOne()

	/**
	 * A missing version answers 404 and an invalid restore 400.
	 *
	 * @return void
	 */
	public function testRestoreMapsMissingTo404AndInvalidTo400(): void {
		$this->signIn('alice');
		$this->service->method('restore')->willReturnOnConsecutiveCalls(
			$this->throwException(new NotFoundException('Version not found')),
			$this->throwException(new InvalidArgumentException('Bad base timestamp')),
		);

		$missing = $this->controller()->restore(id: 'v-1');
		$invalid = $this->controller()->restore(id: 'v-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Version not found'], $missing->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $invalid->getStatus());
		$this->assertSame(['message' => 'Bad base timestamp'], $invalid->getData());
	}//end testRestoreMapsMissingTo404AndInvalidTo400()
}//end class
