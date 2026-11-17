<?php

/**
 * Unit tests for marking a login privileged and answering access requests.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\PrivilegedAccess
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

namespace OCA\Keepiq\Tests\Unit\Service\PrivilegedAccess;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Db\AccessRequestMapper;
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\PrivilegedLogin;
use OCA\Keepiq\Db\PrivilegedLoginMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\PrivilegedAccess\AccessAsk;
use OCA\Keepiq\Service\PrivilegedAccess\AccessNarrowing;
use OCA\Keepiq\Service\PrivilegedAccess\AccessWindow;
use OCA\Keepiq\Service\PrivilegedAccess\AddressRange;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedAccessService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(PrivilegedAccessService::class)]
#[UsesClass(AccessAsk::class)]
#[UsesClass(AccessNarrowing::class)]
#[UsesClass(AccessRequest::class)]
#[UsesClass(AccessWindow::class)]
#[UsesClass(AddressRange::class)]
#[UsesClass(AuditEntry::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(PrivilegedLogin::class)]
#[UsesClass(Secret::class)]
#[UsesClass(ShareTarget::class)]
#[UsesClass(ShareRowVerdict::class)]
class PrivilegedAccessServiceTest extends TestCase {

	/**
	 * 12:00 UTC on the scenario's day.
	 *
	 * @var int
	 */
	private const NOW = 1794830400;

	/**
	 * The secrets by id.
	 *
	 * @var array<string,Secret>
	 */
	private array $secretRows = [];

	/**
	 * The privileged marks.
	 *
	 * @var PrivilegedLoginMapper&MockObject
	 */
	private PrivilegedLoginMapper $flags;

	/**
	 * The access requests.
	 *
	 * @var AccessRequestMapper&MockObject
	 */
	private AccessRequestMapper $requests;

	/**
	 * The notification dispatcher.
	 *
	 * @var NotificationService&MockObject
	 */
	private NotificationService $notifications;

	/**
	 * The audit events recorded.
	 *
	 * @var list<AuditEvent>
	 */
	private array $recorded = [];

	/**
	 * The notifications sent, as [subject, recipient, params].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $sent = [];

	/**
	 * Whether the source s1 is marked.
	 *
	 * @var bool
	 */
	private bool $privileged = true;

	/**
	 * Whether the audit trail refuses writes.
	 *
	 * @var bool
	 */
	private bool $auditFails = false;

	/**
	 * The logger handed to the service.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up: alice owns s1, bob holds copy c1 of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secretRows = [
			's1' => self::secret(id: 's1', ownerId: 'alice'),
			'c1' => self::secret(id: 'c1', ownerId: 'bob'),
		];
		$this->flags = $this->createMock(PrivilegedLoginMapper::class);
		$this->flags->method('privilegedAmong')->willReturnCallback(fn (array $ids): array => $this->privileged === true ? array_values(array_intersect($ids, ['s1'])) : []);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->requests = $this->createMock(AccessRequestMapper::class);
		$this->requests->method('insert')->willReturnArgument(0);
		$this->requests->method('update')->willReturnArgument(0);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->notifications->method('notify')->willReturnCallback(function (string $subject, string $recipientId, array $params = []): bool {
			$this->sent[] = [$subject, $recipientId, $params];
			return true;
		});
	}//end setUp()

	/**
	 * A secret held in a user's vault.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The holder
	 *
	 * @return Secret
	 */
	private static function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Production database');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end secret()

	/**
	 * The service under test.
	 *
	 * @return PrivilegedAccessService
	 */
	private function service(): PrivilegedAccessService {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(fn (string $id): Secret => $this->secretRows[$id] ?? throw new DoesNotExistException('none'));

		$row = new ShareTarget();
		$row->setSourceSecretId('s1');
		$row->setSecretId('c1');
		$row->setTargetUserId('bob');
		$rows = $this->createMock(ShareRowJudge::class);
		$rows->method('forCopy')->willReturnCallback(
			static fn (Secret $secret): ShareRowVerdict => $secret->getId() === 'c1'
				? new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $row)
				: new ShareRowVerdict(state: ShareRowVerdict::NONE)
		);
		$rows->method('forPair')->willReturn(new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $row));

		$audit = $this->createMock(AuditService::class);
		$audit->method('record')->willReturnCallback(function (AuditEvent $event): AuditEntry {
			if ($this->auditFails === true) {
				throw new RuntimeException('audit store offline');
			}

			$this->recorded[] = $event;
			return new AuditEntry();
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new PrivilegedAccessService(
			secrets: $secrets,
			flags: $this->flags,
			requests: $this->requests,
			rows: $rows,
			notifications: $this->notifications,
			audit: $audit,
			time: $time,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A moment on the scenario's day, in UTC.
	 *
	 * @param string $time `H:i`
	 *
	 * @return DateTimeImmutable
	 */
	private static function at(string $time): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-16T' . $time . ':00+00:00');
	}//end at()

	/**
	 * A pending request of bob on s1 from 14:00 to 16:00.
	 *
	 * @return AccessRequest
	 */
	private function pending(): AccessRequest {
		$request = new AccessRequest();
		$request->setId('r1');
		$request->setSecretId('s1');
		$request->setOwnerId('alice');
		$request->setRequesterId('bob');
		$request->setStatus(AccessRequest::STATUS_PENDING);
		$request->setRequestedStart(DateTime::createFromImmutable(self::at(time: '14:00')));
		$request->setRequestedEnd(DateTime::createFromImmutable(self::at(time: '16:00')));
		$this->requests->method('findById')->with('r1')->willReturn($request);
		return $request;
	}//end pending()

	/**
	 * The owner marks the login: one row and an audit entry.
	 *
	 * @return void
	 */
	public function testTheOwnerMarksALogin(): void {
		$this->privileged = false;
		$this->flags->expects($this->once())->method('insert')->with($this->callback(
			static fn (PrivilegedLogin $flag): bool => $flag->getSecretId() === 's1' && $flag->getOwnerId() === 'alice'
		))->willReturnArgument(0);

		$this->assertTrue($this->service()->setPrivileged(secretId: 's1', ownerId: 'alice', privileged: true));
		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_MARKED, $this->recorded[0]->getEventType());
		$this->assertSame('alice', $this->recorded[0]->getActorId());
	}//end testTheOwnerMarksALogin()

	/**
	 * Unmarking removes the row; marking twice changes nothing.
	 *
	 * @return void
	 */
	public function testUnmarkingRemovesTheRowAndRemarkingIsANoOp(): void {
		$this->flags->expects($this->once())->method('deleteBySecret')->with('s1');
		$this->flags->expects($this->never())->method('insert');

		$service = $this->service();
		$this->assertTrue($service->setPrivileged(secretId: 's1', ownerId: 'alice', privileged: true));
		$this->assertSame([], $this->recorded);
		$this->assertFalse($service->setPrivileged(secretId: 's1', ownerId: 'alice', privileged: false));
		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_UNMARKED, $this->recorded[0]->getEventType());
	}//end testUnmarkingRemovesTheRowAndRemarkingIsANoOp()

	/**
	 * A recipient cannot mark their copy, and nobody marks another's login.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerMarks(): void {
		$this->flags->expects($this->never())->method('insert');
		$service = $this->service();

		try {
			$service->setPrivileged(secretId: 'c1', ownerId: 'bob', privileged: false);
			$this->fail('a copy was marked');
		} catch (ForbiddenException $exception) {
			$this->assertSame('Only the owner of a login can mark it or answer requests', $exception->getMessage());
		}

		$this->expectException(NotFoundException::class);
		$service->setPrivileged(secretId: 's1', ownerId: 'mallory', privileged: true);
	}//end testOnlyTheOwnerMarks()

	/**
	 * The recipient asks for a window: a pending row, an audit entry and a
	 * notification to the owner naming the window and the reason.
	 *
	 * @return void
	 */
	public function testARecipientRequestsAWindow(): void {
		$request = $this->service()->request(
			copyId: 'c1',
			requesterId: 'bob',
			ask: new AccessAsk(startsAt: self::at(time: '14:00'), endsAt: self::at(time: '16:00'), reason: '  incident 4711 ')
		);

		$this->assertSame('s1', $request->getSecretId());
		$this->assertSame('alice', $request->getOwnerId());
		$this->assertSame('bob', $request->getRequesterId());
		$this->assertSame('incident 4711', $request->getReason());
		$this->assertSame(AccessRequest::STATUS_PENDING, $request->getStatus());

		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_REQUESTED, $this->recorded[0]->getEventType());
		$this->assertSame('s1', $this->recorded[0]->getObjectId());
		$this->assertSame(
			['requestId' => $request->getId(), 'startsAt' => '2026-11-16T14:00:00+00:00', 'endsAt' => '2026-11-16T16:00:00+00:00'],
			$this->recorded[0]->getMetadata()
		);
		$this->assertSame('privileged_access_requested', $this->sent[0][0]);
		$this->assertSame('alice', $this->sent[0][1]);
		$this->assertSame('bob', $this->sent[0][2]['requester']);
		$this->assertSame('incident 4711', $this->sent[0][2]['reason']);
		$this->assertSame('s1', $this->sent[0][2]['secret_id']);
	}//end testARecipientRequestsAWindow()

	/**
	 * A request is refused on an ordinary login, a past window, a missing
	 * reason, and by the owner.
	 *
	 * @return void
	 */
	public function testInvalidRequestsAreRefused(): void {
		$this->requests->expects($this->never())->method('insert');
		$cases = [
			'The owner of a login does not need to ask for access' => ['s1', 'alice', '14:00', '16:00', 'x'],
			'The window must end in the future' => ['c1', 'bob', '09:00', '11:00', 'x'],
			'A reason of at most 500 characters is required' => ['c1', 'bob', '14:00', '16:00', '   '],
			'The window must end after it starts' => ['c1', 'bob', '16:00', '14:00', 'x'],
		];
		foreach ($cases as $message => [$copyId, $userId, $from, $to, $reason]) {
			try {
				$this->service()->request(copyId: $copyId, requesterId: $userId, ask: new AccessAsk(startsAt: self::at(time: $from), endsAt: self::at(time: $to), reason: $reason));
				$this->fail('accepted: ' . $message);
			} catch (InvalidArgumentException $exception) {
				$this->assertSame($message, $exception->getMessage());
			}
		}

		$this->privileged = false;
		$this->expectExceptionMessage('This login does not need approval');
		$this->service()->request(copyId: 'c1', requesterId: 'bob', ask: new AccessAsk(startsAt: self::at(time: '14:00'), endsAt: self::at(time: '16:00'), reason: 'x'));
	}//end testInvalidRequestsAreRefused()

	/**
	 * The owner approves for a shorter window and the office range; the
	 * requester is told, with a link to their own copy.
	 *
	 * @return void
	 */
	public function testTheOwnerApprovesANarrowerWindowAndRange(): void {
		$this->pending();

		$approved = $this->service()->approve(
			requestId: 'r1',
			ownerId: 'alice',
			narrowing: new AccessNarrowing(startsAt: self::at(time: '14:30'), ranges: ['192.0.2.7/24', '2001:db8::/32'])
		);

		$this->assertSame(AccessRequest::STATUS_APPROVED, $approved->getStatus());
		$this->assertSame('2026-11-16T14:30:00+00:00', $approved->getApprovedStart()->format(DATE_ATOM));
		$this->assertSame('2026-11-16T16:00:00+00:00', $approved->getApprovedEnd()->format(DATE_ATOM));
		$this->assertSame(['192.0.2.0/24', '2001:db8::/32'], $approved->rangeList());
		$this->assertTrue($approved->approvedWindow()->allows(now: self::at(time: '15:00'), address: '192.0.2.99'));

		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_APPROVED, $this->recorded[0]->getEventType());
		$this->assertSame(['192.0.2.0/24', '2001:db8::/32'], $this->recorded[0]->getMetadata()['ranges']);
		$this->assertSame(['privileged_access_result', 'bob'], [$this->sent[0][0], $this->sent[0][1]]);
		$this->assertSame('approved', $this->sent[0][2]['result']);
		$this->assertSame('c1', $this->sent[0][2]['secret_id']);
	}//end testTheOwnerApprovesANarrowerWindowAndRange()

	/**
	 * An approval may not widen the request, and an answered request stays answered.
	 *
	 * @return void
	 */
	public function testAnApprovalMayNotWidenOrRepeat(): void {
		$request = $this->pending();
		$this->requests->expects($this->never())->method('update');

		try {
			$this->service()->approve(requestId: 'r1', ownerId: 'alice', narrowing: new AccessNarrowing(endsAt: self::at(time: '17:00')));
			$this->fail('widened');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('An approval may only narrow the requested window', $exception->getMessage());
		}

		$request->setStatus(AccessRequest::STATUS_DENIED);
		$this->expectExceptionMessage('The request was already answered');
		$this->service()->approve(requestId: 'r1', ownerId: 'alice');
	}//end testAnApprovalMayNotWidenOrRepeat()

	/**
	 * Someone other than the owner cannot answer: the request reads as unknown.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerAnswers(): void {
		$this->pending();
		$this->expectException(NotFoundException::class);
		$this->service()->deny(requestId: 'r1', ownerId: 'bob');
	}//end testOnlyTheOwnerAnswers()

	/**
	 * Denying is stored, audited and told to the requester.
	 *
	 * @return void
	 */
	public function testTheOwnerDenies(): void {
		$this->pending();

		$denied = $this->service()->deny(requestId: 'r1', ownerId: 'alice');

		$this->assertSame(AccessRequest::STATUS_DENIED, $denied->getStatus());
		$this->assertNull($denied->approvedWindow());
		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_DENIED, $this->recorded[0]->getEventType());
		$this->assertSame(['requestId' => 'r1', 'requesterId' => 'bob'], $this->recorded[0]->getMetadata());
		$this->assertSame('denied', $this->sent[0][2]['result']);
	}//end testTheOwnerDenies()

	/**
	 * The owner sees every request; the recipient only their own, through their copy.
	 *
	 * @return void
	 */
	public function testStatusPerRole(): void {
		$this->requests->method('findBySecret')->willReturnCallback(
			static fn (string $secretId, ?string $requesterId = null): array => $secretId === 's1' ? [] : throw new \LogicException('wrong secret')
		);
		$service = $this->service();

		$this->assertSame(['role' => 'owner', 'privileged' => true, 'requests' => []], $service->status(secretId: 's1', userId: 'alice'));
		$this->assertSame(['role' => 'recipient', 'privileged' => true, 'requests' => []], $service->status(secretId: 'c1', userId: 'bob'));

		$this->expectException(NotFoundException::class);
		$service->status(secretId: 's1', userId: 'bob');
	}//end testStatusPerRole()

	/**
	 * A deleted secret drops its mark and requests.
	 *
	 * @return void
	 */
	public function testForgetDropsTheMarkAndRequests(): void {
		$this->flags->expects($this->once())->method('deleteBySecret')->with('s1');
		$this->requests->expects($this->once())->method('deleteBySecret')->with('s1');

		$this->service()->forget(secretId: 's1');
	}//end testForgetDropsTheMarkAndRequests()

	/**
	 * The owner's waiting requests come back serialised.
	 *
	 * @return void
	 */
	public function testPendingForOwner(): void {
		$request = new AccessRequest();
		$request->setId('r1');
		$request->setReason('incident 4711');
		$this->requests->method('findPendingForOwner')->with('alice')->willReturn([$request]);

		$pending = $this->service()->pendingForOwner(ownerId: 'alice');

		$this->assertCount(1, $pending);
		$this->assertSame('r1', $pending[0]['id']);
		$this->assertSame('incident 4711', $pending[0]['reason']);
		$this->assertSame([], $pending[0]['ranges']);
	}//end testPendingForOwner()

	/**
	 * An unknown request id reads as not found, and nothing is updated.
	 *
	 * @return void
	 */
	public function testAnUnknownRequestIsNotFound(): void {
		$this->requests->method('findById')->with('r404')->willThrowException(new DoesNotExistException('none'));
		$this->requests->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Access request not found');
		$this->service()->approve(requestId: 'r404', ownerId: 'alice');
	}//end testAnUnknownRequestIsNotFound()

	/**
	 * A request whose login is a received copy of the caller cannot be
	 * answered by them: it reads as not found, not as forbidden.
	 *
	 * @return void
	 */
	public function testARequestOnAReceivedCopyReadsAsNotFound(): void {
		$request = new AccessRequest();
		$request->setId('r2');
		$request->setSecretId('c1');
		$request->setOwnerId('bob');
		$request->setRequesterId('carol');
		$request->setStatus(AccessRequest::STATUS_PENDING);
		$this->requests->method('findById')->with('r2')->willReturn($request);
		$this->requests->expects($this->never())->method('update');

		try {
			$this->service()->deny(requestId: 'r2', ownerId: 'bob');
			$this->fail('a copy holder answered a request');
		} catch (NotFoundException $exception) {
			$this->assertSame('Access request not found', $exception->getMessage());
		}

		$this->assertSame([], $this->recorded);
		$this->assertSame([], $this->sent);
	}//end testARequestOnAReceivedCopyReadsAsNotFound()

	/**
	 * A secret id nobody holds reads as a missing secret.
	 *
	 * @return void
	 */
	public function testAMissingSecretIsNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Secret not found');
		$this->service()->status(secretId: 'gone', userId: 'alice');
	}//end testAMissingSecretIsNotFound()

	/**
	 * A failing audit write is logged with its event type, and the step
	 * itself still completes.
	 *
	 * @return void
	 */
	public function testAFailingAuditWriteIsLoggedAndTheStepCompletes(): void {
		$this->privileged = false;
		$this->auditFails = true;
		$this->flags->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->logger->expects($this->once())->method('error')->with(
			'Keepiq: privileged access step could not be audited: audit store offline',
			['app' => 'keepiq', 'eventType' => AuditEventTypes::PRIVILEGED_ACCESS_MARKED]
		);

		$this->assertTrue($this->service()->setPrivileged(secretId: 's1', ownerId: 'alice', privileged: true));
		$this->assertSame([], $this->recorded);
	}//end testAFailingAuditWriteIsLoggedAndTheStepCompletes()
}//end class
