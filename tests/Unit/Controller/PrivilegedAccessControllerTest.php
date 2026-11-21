<?php

/**
 * Unit tests for the privileged access endpoints.
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

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Keepiq\Controller\PrivilegedAccessController;
use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\PrivilegedAccess\AccessAsk;
use OCA\Keepiq\Service\PrivilegedAccess\AccessNarrowing;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedAccessService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(PrivilegedAccessController::class)]
#[UsesClass(AccessAsk::class)]
#[UsesClass(AccessNarrowing::class)]
#[UsesClass(AccessRequest::class)]
class PrivilegedAccessControllerTest extends TestCase {

	/**
	 * The service.
	 *
	 * @var PrivilegedAccessService&MockObject
	 */
	private PrivilegedAccessService $service;

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(PrivilegedAccessService::class);
	}//end setUp()

	/**
	 * The controller, signed in as the given user or as nobody.
	 *
	 * @param string|null $uid The caller
	 *
	 * @return PrivilegedAccessController
	 */
	private function controller(?string $uid = 'bob'): PrivilegedAccessController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new PrivilegedAccessController(request: $this->createMock(IRequest::class), service: $this->service, userSession: $session);
	}//end controller()

	/**
	 * A request passes the window as moments and answers 201.
	 *
	 * @return void
	 */
	public function testRequestParsesTheWindow(): void {
		$created = new AccessRequest();
		$created->setId('r1');
		$this->service->expects($this->once())->method('request')->with(
			'c1',
			'bob',
			new AccessAsk(
				startsAt: new DateTimeImmutable('2026-11-16T14:00:00+00:00'),
				endsAt: new DateTimeImmutable('2026-11-16T16:00:00+00:00'),
				reason: 'incident 4711'
			)
		)->willReturn($created);

		$response = $this->controller()->request(id: 'c1', startsAt: '2026-11-16T14:00:00+00:00', endsAt: '2026-11-16T16:00:00+00:00', reason: 'incident 4711');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('r1', $response->getData()['id']);
	}//end testRequestParsesTheWindow()

	/**
	 * A missing or unreadable date answers 400 without calling the service.
	 *
	 * @return void
	 */
	public function testBadDatesAnswerBadRequest(): void {
		$this->service->expects($this->never())->method('request');

		$missing = $this->controller()->request(id: 'c1', startsAt: '', endsAt: '2026-11-16T16:00:00+00:00', reason: 'x');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $missing->getStatus());
		$this->assertSame('A start and an end are required', $missing->getData()['message']);

		$garbled = $this->controller()->request(id: 'c1', startsAt: 'soon', endsAt: '2026-11-16T16:00:00+00:00', reason: 'x');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $garbled->getStatus());
		$this->assertSame('Not a date: soon', $garbled->getData()['message']);
	}//end testBadDatesAnswerBadRequest()

	/**
	 * Approve passes the optional narrowing and drops empty ranges.
	 *
	 * @return void
	 */
	public function testApprovePassesTheNarrowing(): void {
		$this->service->expects($this->once())->method('approve')->with(
			'r1',
			'alice',
			new AccessNarrowing(
				startsAt: null,
				endsAt: new DateTimeImmutable('2026-11-16T15:00:00+00:00'),
				ranges: ['192.0.2.0/24']
			)
		)->willReturn(new AccessRequest());

		$response = $this->controller(uid: 'alice')->approve(requestId: 'r1', startsAt: '', endsAt: '2026-11-16T15:00:00+00:00', ranges: ['192.0.2.0/24', ' ']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testApprovePassesTheNarrowing()

	/**
	 * The service's refusals map to 404, 403 and 400; no session to 401.
	 *
	 * @return void
	 */
	public function testRefusalsAreMapped(): void {
		$this->service->method('status')->willThrowException(new NotFoundException('Secret not found'));
		$this->service->method('setPrivileged')->willThrowException(new ForbiddenException('Only the owner of a login can mark it or answer requests'));
		$this->service->method('deny')->willThrowException(new InvalidArgumentException('The request was already answered'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->status(id: 's1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->update(id: 'c1', privileged: true)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->deny(requestId: 'r1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->pending()->getStatus());
	}//end testRefusalsAreMapped()

	/**
	 * Marking, status and the pending list answer the service's result.
	 *
	 * @return void
	 */
	public function testMarkStatusAndPending(): void {
		$this->service->method('setPrivileged')->with('s1', 'alice', true)->willReturn(true);
		$this->service->method('status')->willReturn(['role' => 'owner', 'privileged' => true, 'requests' => []]);
		$this->service->method('pendingForOwner')->with('alice')->willReturn([['id' => 'r1']]);

		$this->assertSame(['privileged' => true], $this->controller(uid: 'alice')->update(id: 's1', privileged: true)->getData());
		$this->assertSame('owner', $this->controller(uid: 'alice')->status(id: 's1')->getData()['role']);
		$this->assertSame(['items' => [['id' => 'r1']]], $this->controller(uid: 'alice')->pending()->getData());
	}//end testMarkStatusAndPending()
}//end class
