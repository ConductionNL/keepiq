<?php

/**
 * Unit tests for the officer queue, hand-off refusals and request ending of RecoveryRequestService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\RecoveryApproval;
use OCA\Keepiq\Db\RecoveryApprovalMapper;
use OCA\Keepiq\Db\RecoveryEnrolmentMapper;
use OCA\Keepiq\Db\RecoveryRequest;
use OCA\Keepiq\Db\RecoveryRequestMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecoveryAudit;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryKeyService;
use OCA\Keepiq\Service\RecoveryPolicyService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Officers see only the open, unlapsed requests of others; refusals are not-found, never a leak.
 */
#[CoversClass(RecoveryRequestService::class)]
#[UsesClass(RecoveryApproval::class)]
#[UsesClass(RecoveryRequest::class)]
#[UsesClass(ForbiddenException::class)]
#[UsesClass(NotFoundException::class)]
class RecoveryRequestOfficerViewTest extends TestCase {

	/** @var RecoveryRequestMapper&MockObject */
	private RecoveryRequestMapper $requests;

	/** @var RecoveryApprovalMapper&MockObject */
	private RecoveryApprovalMapper $approvals;

	/** @var RecoveryEnrolmentMapper&MockObject */
	private RecoveryEnrolmentMapper $enrolments;

	/** @var RecoveryPolicyService&MockObject */
	private RecoveryPolicyService $policy;

	/**
	 * Builds the collaborators; officer and carol are officers, the threshold is two.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->requests   = $this->createMock(RecoveryRequestMapper::class);
		$this->approvals  = $this->createMock(RecoveryApprovalMapper::class);
		$this->enrolments = $this->createMock(RecoveryEnrolmentMapper::class);
		$this->policy     = $this->createMock(RecoveryPolicyService::class);
		$this->policy->method('isOfficer')->willReturnCallback(
			static fn (string $userId): bool => in_array($userId, ['officer', 'carol'], true)
		);
		$this->policy->method('threshold')->willReturn(2);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return RecoveryRequestService
	 */
	private function service(): RecoveryRequestService {
		return new RecoveryRequestService(
			requests: $this->requests,
			approvals: $this->approvals,
			enrolments: $this->enrolments,
			enrolment: $this->createMock(RecoveryEnrolmentService::class),
			keys: $this->createMock(RecoveryKeyService::class),
			policy: $this->policy,
			notifications: $this->createMock(NotificationService::class),
			audit: $this->createMock(RecoveryAudit::class),
		);
	}//end service()

	/**
	 * A request.
	 *
	 * @param string        $id      The id
	 * @param string        $userId  Who filed it
	 * @param string        $status  Its status
	 * @param DateTime|null $expires When it lapses
	 *
	 * @return RecoveryRequest
	 */
	private function request(string $id, string $userId, string $status, ?DateTime $expires): RecoveryRequest {
		$request = new RecoveryRequest();
		$request->setId($id);
		$request->setUserId($userId);
		$request->setStatus($status);
		$request->setPurpose('password');
		$request->setRequestPublicKey('pk-'.$id);
		$request->setExpiresAt($expires);
		$request->setSealedResult('sealed-'.$id);
		return $request;
	}//end request()

	/**
	 * A decision.
	 *
	 * @param string $officer  The officer
	 * @param string $decision approve or decline
	 *
	 * @return RecoveryApproval
	 */
	private function decision(string $officer, string $decision): RecoveryApproval {
		$approval = new RecoveryApproval();
		$approval->setOfficerUid($officer);
		$approval->setDecision($decision);
		return $approval;
	}//end decision()

	/**
	 * The queue skips the officer's own and lapsed requests and counts each approver once.
	 *
	 * @return void
	 */
	public function testTheOfficerQueueListsOnlyOthersOpenRequests(): void {
		$future = new DateTime('+1 day');
		$open   = $this->request('r1', 'dave', RecoveryRequestService::STATUS_PENDING, $future);
		$open->setHandledBy('carol');
		$own    = $this->request('r2', 'officer', RecoveryRequestService::STATUS_PENDING, $future);
		$lapsed = $this->request('r3', 'erin', RecoveryRequestService::STATUS_APPROVED, new DateTime('-1 minute'));
		$never  = $this->request('r4', 'frank', RecoveryRequestService::STATUS_PENDING, null);
		$this->requests->expects($this->once())->method('findByStatuses')
			->with(RecoveryRequestService::OPEN)
			->willReturn([$open, $own, $lapsed, $never]);
		$this->approvals->method('findByRequest')->with('r1')->willReturn(
			[$this->decision('officer', 'approve'), $this->decision('officer', 'approve'), $this->decision('carol', 'decline')]
		);

		$rows = $this->service()->forOfficer(officerUid: 'officer');

		$this->assertCount(1, $rows);
		$this->assertSame('r1', $rows[0]['id']);
		$this->assertSame('dave', $rows[0]['userId']);
		$this->assertSame('pk-r1', $rows[0]['requestPublicKey']);
		$this->assertSame($future->format('c'), $rows[0]['expiresAt']);
		$this->assertSame(1, $rows[0]['approvals']);
		$this->assertSame(2, $rows[0]['threshold']);
		$this->assertTrue($rows[0]['approvedByMe']);
		$this->assertTrue($rows[0]['handedOff']);
	}//end testTheOfficerQueueListsOnlyOthersOpenRequests()

	/**
	 * A request another officer approved is not marked as approved by me.
	 *
	 * @return void
	 */
	public function testARequestApprovedByAnotherIsNotMine(): void {
		$this->requests->method('findByStatuses')->willReturn(
			[$this->request('r1', 'dave', RecoveryRequestService::STATUS_PENDING, new DateTime('+1 day'))]
		);
		$this->approvals->method('findByRequest')->willReturn([$this->decision('carol', 'approve')]);

		$rows = $this->service()->forOfficer(officerUid: 'officer');

		$this->assertFalse($rows[0]['approvedByMe']);
		$this->assertFalse($rows[0]['handedOff']);
		$this->assertSame(1, $rows[0]['approvals']);
	}//end testARequestApprovedByAnotherIsNotMine()

	/**
	 * Someone who is not an officer cannot see the queue.
	 *
	 * @return void
	 */
	public function testANonOfficerCannotSeeTheQueue(): void {
		$this->requests->expects($this->never())->method('findByStatuses');
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only a recovery officer can do this');

		$this->service()->forOfficer(officerUid: 'mallory');
	}//end testANonOfficerCannotSeeTheQueue()

	/**
	 * A hand-off for a request that does not exist is not found.
	 *
	 * @return void
	 */
	public function testAHandoffForAMissingRequestIsNotFound(): void {
		$this->requests->method('findById')->willThrowException(new DoesNotExistException('none'));
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Request not found');

		$this->service()->handoff(id: 'nope', officerUid: 'officer');
	}//end testAHandoffForAMissingRequestIsNotFound()

	/**
	 * A hand-off whose enrolment vanished is not found either.
	 *
	 * @return void
	 */
	public function testAHandoffWithoutItsEnrolmentIsNotFound(): void {
		$request = $this->request('r1', 'dave', RecoveryRequestService::STATUS_APPROVED, new DateTime('+1 day'));
		$request->setEnrolmentId('enrol-1');
		$this->requests->method('findById')->with('r1')->willReturn($request);
		$this->approvals->method('findByRequest')->willReturn([$this->decision('officer', 'approve')]);
		$this->enrolments->expects($this->once())->method('findById')->with('enrol-1')
			->willThrowException(new DoesNotExistException('none'));
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Request not found');

		$this->service()->handoff(id: 'r1', officerUid: 'officer');
	}//end testAHandoffWithoutItsEnrolmentIsNotFound()

	/**
	 * A blank sealed result is refused before anything is loaded.
	 *
	 * @return void
	 */
	public function testABlankSealedResultIsRefused(): void {
		$this->requests->expects($this->never())->method('findById');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('sealedResult is required');

		$this->service()->postSealed(id: 'r1', officerUid: 'officer', sealed: "  \n");
	}//end testABlankSealedResultIsRefused()

	/**
	 * A user without any request has no status to show.
	 *
	 * @return void
	 */
	public function testAUserWithoutARequestHasNoStatus(): void {
		$this->requests->method('findByUser')->with('dave')->willReturn([]);

		$this->assertNull($this->service()->forUser(userId: 'dave'));
	}//end testAUserWithoutARequestHasNoStatus()

	/**
	 * Ending a suite declines only its open requests and drops their sealed results.
	 *
	 * @return void
	 */
	public function testEndingASuiteDeclinesOnlyOpenRequests(): void {
		$pending   = $this->request('r1', 'dave', RecoveryRequestService::STATUS_PENDING, new DateTime('+1 day'));
		$approved  = $this->request('r2', 'dave', RecoveryRequestService::STATUS_APPROVED, new DateTime('+1 day'));
		$fulfilled = $this->request('r3', 'dave', RecoveryRequestService::STATUS_FULFILLED, null);
		$this->requests->method('findBySuite')->with('suite-1')->willReturn([$pending, $approved, $fulfilled]);
		$updated = [];
		$this->requests->expects($this->exactly(2))->method('update')->willReturnCallback(
			static function (RecoveryRequest $request) use (&$updated): RecoveryRequest {
				$updated[] = $request->getId();
				return $request;
			}
		);

		$this->assertSame(2, $this->service()->endForSuite(suiteId: 'suite-1'));
		$this->assertSame(['r1', 'r2'], $updated);
		$this->assertSame(RecoveryRequestService::STATUS_DECLINED, $pending->getStatus());
		$this->assertNull($pending->getSealedResult());
		$this->assertSame(RecoveryRequestService::STATUS_FULFILLED, $fulfilled->getStatus());
		$this->assertSame('sealed-r3', $fulfilled->getSealedResult());
	}//end testEndingASuiteDeclinesOnlyOpenRequests()
}//end class
