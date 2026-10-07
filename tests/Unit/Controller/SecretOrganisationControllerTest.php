<?php

/**
 * Unit tests for the favourite, tag and used endpoints (vault-favourites-tags-and-last-used).
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

use OCA\Keepiq\Controller\SecretOrganisationController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Service\SecretOrganisationService;
use OCA\Keepiq\Service\SecretTagNormaliser;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The endpoints run the real service: only the database layer is a double.
 */
class SecretOrganisationControllerTest extends TestCase {
	/** @var SecretMapper&MockObject */
	private SecretMapper $mapper;

	/** @var SecretTagMapper&MockObject */
	private SecretTagMapper $tags;

	/**
	 * Wire the mappers: s-alice is alice's row, s-bob is bob's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->tags   = $this->createMock(SecretTagMapper::class);
		$this->mapper->method('findById')->willReturnCallback(
			static function (string $id): Secret {
				$owners = ['s-alice' => 'alice', 's-bob' => 'bob'];
				if (isset($owners[$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				$secret = new Secret();
				$secret->setId($id);
				$secret->setOwnerType('user');
				$secret->setOwnerId($owners[$id]);
				return $secret;
			}
		);
	}//end setUp()

	/**
	 * The controller for a signed-in user, or for nobody.
	 *
	 * @param string|null $uid The signed-in user, null for none
	 *
	 * @return SecretOrganisationController
	 */
	private function controller(?string $uid = 'alice'): SecretOrganisationController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SecretOrganisationController(
			request: $this->createMock(IRequest::class),
			organisation: new SecretOrganisationService(
				mapper: $this->mapper,
				tagMapper: $this->tags,
				normaliser: new SecretTagNormaliser(),
			),
			userSession: $session,
		);
	}//end controller()

	/**
	 * The used-route answers 404 for another user's secret and records nothing.
	 *
	 * @return void
	 */
	public function testUsedRouteCannotProbeAnotherUsersSecret(): void {
		$this->mapper->expects($this->never())->method('markUsed');

		$foreign = $this->controller()->used('s-bob');
		$missing = $this->controller()->used('s-missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame($missing->getData(), $foreign->getData());
	}//end testUsedRouteCannotProbeAnotherUsersSecret()

	/**
	 * The holder's fill is recorded.
	 *
	 * @return void
	 */
	public function testUsedRouteRecordsTheHoldersFill(): void {
		$this->mapper->expects($this->once())->method('markUsed')->willReturn(1);

		$this->assertSame(Http::STATUS_OK, $this->controller()->used('s-alice')->getStatus());
	}//end testUsedRouteRecordsTheHoldersFill()

	/**
	 * Starring and tagging another user's secret is 404 and writes nothing.
	 *
	 * @return void
	 */
	public function testFavouriteAndTagsOnAnotherUsersSecretAreNotFound(): void {
		$this->mapper->expects($this->never())->method('setFavourite');
		$this->tags->expects($this->never())->method('replaceForSecret');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->favourite('s-bob', true)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->tags('s-bob', ['x'])->getStatus());
	}//end testFavouriteAndTagsOnAnotherUsersSecretAreNotFound()

	/**
	 * The holder stars and tags their own row.
	 *
	 * @return void
	 */
	public function testHolderStarsAndTags(): void {
		$this->mapper->method('setFavourite')->willReturn(1);

		$star = $this->controller()->favourite('s-alice', true);
		$tags = $this->controller()->tags('s-alice', ['Finance']);

		$this->assertSame(['id' => 's-alice', 'favourite' => true], $star->getData());
		$this->assertSame(['id' => 's-alice', 'tags' => ['finance']], $tags->getData());
	}//end testHolderStarsAndTags()

	/**
	 * A tag over 32 characters is a 400, not a silent truncation.
	 *
	 * @return void
	 */
	public function testOverlongTagIsABadRequest(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->tags('s-alice', [str_repeat('a', 33)])->getStatus());
	}//end testOverlongTagIsABadRequest()

	/**
	 * Without a session every endpoint answers 401.
	 *
	 * @return void
	 */
	public function testSignedOutIsUnauthorised(): void {
		$controller = $this->controller(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->used('s-alice')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->favourite('s-alice', true)->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->tags('s-alice', [])->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->tagIndex()->getStatus());
	}//end testSignedOutIsUnauthorised()
}//end class
