<?php

/**
 * Unit tests for the serve-time check on privileged copies.
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
use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Db\AccessRequestMapper;
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\PrivilegedLoginMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\PrivilegedAccessRequiredException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\PrivilegedAccess\AccessWindow;
use OCA\Keepiq\Service\PrivilegedAccess\AddressRange;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCA\Keepiq\Service\ShareRowJudge;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(PrivilegedCopyGate::class)]
#[UsesClass(AccessRequest::class)]
#[UsesClass(AccessWindow::class)]
#[UsesClass(AddressRange::class)]
#[UsesClass(Secret::class)]
#[UsesClass(ShareTarget::class)]
#[UsesClass(JudgedShareRows::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(AuditEntry::class)]
#[UsesClass(PrivilegedAccessRequiredException::class)]
class PrivilegedCopyGateTest extends TestCase {

	/**
	 * 15:00 UTC on the scenario's day.
	 *
	 * @var int
	 */
	private const NOW = 1794841200;

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
	 * The audit trail.
	 *
	 * @var AuditService&MockObject
	 */
	private AuditService $audit;

	/**
	 * The client address the request reports.
	 *
	 * @var string
	 */
	private string $address = '192.0.2.10';

	/**
	 * The audit events recorded.
	 *
	 * @var list<AuditEvent>
	 */
	private array $recorded = [];

	/**
	 * Set up the doubles: bob holds copy c1 of alice's source s1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flags    = $this->createMock(PrivilegedLoginMapper::class);
		$this->requests = $this->createMock(AccessRequestMapper::class);
		$this->audit    = $this->createMock(AuditService::class);
		$this->audit->method('record')->willReturnCallback(function (AuditEvent $event) {
			$this->recorded[] = $event;
			return new AuditEntry();
		});
	}//end setUp()

	/**
	 * The gate, with bob holding copy c1 of source s1.
	 *
	 * @return PrivilegedCopyGate
	 */
	private function gate(): PrivilegedCopyGate {
		$row = new ShareTarget();
		$row->setSourceSecretId('s1');
		$row->setSecretId('c1');
		$row->setTargetUserId('bob');

		$rows = $this->createMock(ShareRowJudge::class);
		$rows->method('rowsOfTarget')->with('bob')->willReturn(new JudgedShareRows(rows: [$row], judge: static fn (): ?string => null));

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturnCallback(fn (): string => $this->address);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new PrivilegedCopyGate(
			flags: $this->flags,
			requests: $this->requests,
			rows: $rows,
			audit: $this->audit,
			request: $request,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end gate()

	/**
	 * A secret with an id and a name.
	 *
	 * @param string $id The id
	 *
	 * @return Secret
	 */
	private static function secret(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Production database');
		$secret->setKey('ciphertext-blob');
		$secret->setOwnerType('user');
		$secret->setOwnerId('bob');
		return $secret;
	}//end secret()

	/**
	 * An approved request for source s1 from 14:00 to 16:00 UTC.
	 *
	 * @param list<string> $ranges CIDR ranges
	 *
	 * @return AccessRequest
	 */
	private static function approved(array $ranges = []): AccessRequest {
		$request = new AccessRequest();
		$request->setId('r1');
		$request->setStatus(AccessRequest::STATUS_APPROVED);
		$request->setApprovedStart(new DateTime('@' . (self::NOW - 3600)));
		$request->setApprovedEnd(new DateTime('@' . (self::NOW + 3600)));
		$request->setRanges((string)json_encode($ranges));
		return $request;
	}//end approved()

	/**
	 * A copy whose source is not privileged is served unchanged, with no audit.
	 *
	 * @return void
	 */
	public function testACopyOfAnOrdinaryLoginIsServedUnchanged(): void {
		$this->flags->method('privilegedAmong')->with(['s1'])->willReturn([]);
		$this->requests->expects($this->never())->method('findApproved');

		$data = ['id' => 'c1', 'key' => 'ciphertext-blob'];
		$this->assertSame($data, $this->gate()->servable(secret: self::secret(id: 'c1'), data: $data, userId: 'bob', channel: 'web', audit: true));
		$this->assertSame([], $this->recorded);
	}//end testACopyOfAnOrdinaryLoginIsServedUnchanged()

	/**
	 * A row that is no copy of the user (the owner's own secret) is always served.
	 *
	 * @return void
	 */
	public function testTheOwnersOwnSecretIsNeverRestricted(): void {
		$this->flags->method('privilegedAmong')->willReturn(['s1']);

		$this->assertTrue($this->gate()->allows(secret: self::secret(id: 's1'), userId: 'bob', channel: 'web', audit: true));
		$this->assertSame([], $this->recorded);
	}//end testTheOwnersOwnSecretIsNeverRestricted()

	/**
	 * Inside an approved window and range the copy is served and the use audited.
	 *
	 * @return void
	 */
	public function testUseInsideTheWindowAndRangeIsServedAndAudited(): void {
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->with('s1', 'bob')->willReturn([self::approved(ranges: ['192.0.2.0/24'])]);

		$this->gate()->assertServable(secret: self::secret(id: 'c1'), userId: 'bob', channel: 'web');

		$this->assertCount(1, $this->recorded);
		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_USED, $this->recorded[0]->getEventType());
		$this->assertSame('bob', $this->recorded[0]->getActorId());
		$this->assertSame('c1', $this->recorded[0]->getObjectId());
		$this->assertSame(['requestId' => 'r1', 'address' => '192.0.2.10', 'channel' => 'web'], $this->recorded[0]->getMetadata());
	}//end testUseInsideTheWindowAndRangeIsServedAndAudited()

	/**
	 * From an address outside the approved range the fetch is refused and audited.
	 *
	 * @return void
	 */
	public function testUseFromAnotherAddressIsRefusedAndAudited(): void {
		$this->address = '198.51.100.7';
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([self::approved(ranges: ['192.0.2.0/24'])]);

		try {
			$this->gate()->assertServable(secret: self::secret(id: 'c1'), userId: 'bob', channel: 'extension');
			$this->fail('no refusal');
		} catch (PrivilegedAccessRequiredException $exception) {
			$this->assertSame(PrivilegedCopyGate::REASON, $exception->getMessage());
			$this->assertSame('privileged_access_required', $exception->policyCode());
		}

		$this->assertCount(1, $this->recorded);
		$this->assertSame(AuditEventTypes::PRIVILEGED_ACCESS_REFUSED, $this->recorded[0]->getEventType());
		$this->assertSame(['address' => '198.51.100.7', 'channel' => 'extension'], $this->recorded[0]->getMetadata());
	}//end testUseFromAnotherAddressIsRefusedAndAudited()

	/**
	 * After the window ended a listed copy loses its ciphertext, without an audit.
	 *
	 * @return void
	 */
	public function testAListedCopyOutsideItsWindowIsWithheldWithoutAudit(): void {
		$ended = self::approved();
		$ended->setApprovedStart(new DateTime('@' . (self::NOW - 7200)));
		$ended->setApprovedEnd(new DateTime('@' . (self::NOW - 3600)));
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([$ended]);

		$secret = self::secret(id: 'c1');
		$served = $this->gate()->servable(secret: $secret, data: $secret->jsonSerialize(), userId: 'bob', channel: 'web');

		$this->assertTrue($served['blocked']);
		$this->assertSame(PrivilegedCopyGate::REASON, $served['blockedReason']);
		$this->assertSame('privileged_access_required', $served['privilegedAccess']);
		$this->assertArrayNotHasKey('key', $served);
		$this->assertArrayNotHasKey('login', $served);
		$this->assertSame([], $this->recorded);
	}//end testAListedCopyOutsideItsWindowIsWithheldWithoutAudit()

	/**
	 * A copy returned after a write or in a conflict body, outside its
	 * window, loses its ciphertext, without an audit.
	 *
	 * @return void
	 */
	public function testACopyReturnedOutsideItsWindowLosesItsCiphertext(): void {
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([]);

		$served = $this->gate()->serialisedFor(secret: self::secret(id: 'c1'), userId: 'bob');

		$this->assertTrue($served['blocked']);
		$this->assertSame('privileged_access_required', $served['privilegedAccess']);
		$this->assertArrayNotHasKey('key', $served);
		$this->assertSame([], $this->recorded);
	}//end testACopyReturnedOutsideItsWindowLosesItsCiphertext()

	/**
	 * A copy returned inside its window keeps its full serialisation.
	 *
	 * @return void
	 */
	public function testACopyReturnedInsideItsWindowIsUnchanged(): void {
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([self::approved()]);

		$secret = self::secret(id: 'c1');
		$this->assertSame($secret->jsonSerialize(), $this->gate()->serialisedFor(secret: $secret, userId: 'bob'));
	}//end testACopyReturnedInsideItsWindowIsUnchanged()

	/**
	 * Without any approved request the copy is refused.
	 *
	 * @return void
	 */
	public function testNoApprovedRequestRefuses(): void {
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([]);

		$this->assertFalse($this->gate()->allows(secret: self::secret(id: 'c1'), userId: 'bob', channel: 'web'));
	}//end testNoApprovedRequestRefuses()

	/**
	 * A stored range that no longer parses grants nothing; a later valid
	 * approval still does.
	 *
	 * @return void
	 */
	public function testAnUnreadableApprovalGrantsNothing(): void {
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([self::approved(ranges: ['not-a-range'])]);

		$this->assertFalse($this->gate()->allows(secret: self::secret(id: 'c1'), userId: 'bob', channel: 'web'));
	}//end testAnUnreadableApprovalGrantsNothing()

	/**
	 * A failing audit write neither opens the gate nor breaks the fetch.
	 *
	 * @return void
	 */
	public function testAFailingAuditKeepsTheRefusal(): void {
		$this->audit = $this->createMock(AuditService::class);
		$this->audit->method('record')->willThrowException(new RuntimeException('db down'));
		$this->flags->method('privilegedAmong')->willReturn(['s1']);
		$this->requests->method('findApproved')->willReturn([]);

		$this->assertFalse($this->gate()->allows(secret: self::secret(id: 'c1'), userId: 'bob', channel: 'web', audit: true));
	}//end testAFailingAuditKeepsTheRefusal()

	/**
	 * The privileged sources are read once per user and request.
	 *
	 * @return void
	 */
	public function testTheRestrictedCopiesAreReadOncePerUser(): void {
		$this->flags->expects($this->once())->method('privilegedAmong')->willReturn([]);
		$gate = $this->gate();

		$gate->allows(secret: self::secret(id: 'c1'), userId: 'bob', channel: 'web');
		$gate->allows(secret: self::secret(id: 'c2'), userId: 'bob', channel: 'web');
	}//end testTheRestrictedCopiesAreReadOncePerUser()
}//end class
