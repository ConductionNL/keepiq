<?php

/**
 * Unit tests for EmergencyAccessService — the emergency-access state machine.
 *
 * Covers: self-designation rejection, level/wait-period validation, confirm
 * idempotency, request/reject/cancel state transitions and their
 * authorization guards, and the wait-period expiry auto-grant logic (only
 * rows past their wait period transition, others are left untouched).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/emergency-access/tasks.md#task-8.1
 * @spec openspec/changes/emergency-access/tasks.md#task-8.3
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EmergencyContact;
use OCA\Doriath\Db\EmergencyContactMapper;
use OCA\Doriath\Db\EmergencyRequest;
use OCA\Doriath\Db\EmergencyRequestMapper;
use OCA\Doriath\Service\EmergencyAccessService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmergencyAccessService.
 */
class EmergencyAccessServiceTest extends TestCase
{
    /**
     * The contact designation mapper mock.
     *
     * @var EmergencyContactMapper&MockObject
     */
    private EmergencyContactMapper&MockObject $contactMapper;

    /**
     * The access-request mapper mock.
     *
     * @var EmergencyRequestMapper&MockObject
     */
    private EmergencyRequestMapper&MockObject $requestMapper;

    /**
     * The service under test.
     *
     * @var EmergencyAccessService
     */
    private EmergencyAccessService $service;

    /**
     * The fixed "now" the time factory returns.
     *
     * @var DateTime
     */
    private DateTime $now;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->now           = new DateTime('2026-07-07T12:00:00+00:00');
        $this->contactMapper = $this->createMock(originalClassName: EmergencyContactMapper::class);
        $this->requestMapper = $this->createMock(originalClassName: EmergencyRequestMapper::class);

        $timeFactory = $this->createMock(originalClassName: ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturnCallback(fn (): DateTime => clone $this->now);

        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new EmergencyAccessService(
            contactMapper: $this->contactMapper,
            requestMapper: $this->requestMapper,
            timeFactory: $timeFactory,
            logger: $logger,
        );
    }//end setUp()

    /**
     * registerContact rejects an owner naming themselves.
     *
     * @return void
     */
    public function testRegisterRejectsSelfDesignation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->registerContact(ownerId: 'alice', contactId: 'alice', waitPeriodHours: 24);
    }//end testRegisterRejectsSelfDesignation()

    /**
     * registerContact rejects an unknown access level.
     *
     * @return void
     */
    public function testRegisterRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->registerContact(ownerId: 'alice', contactId: 'bob', waitPeriodHours: 24, level: 'god-mode');
    }//end testRegisterRejectsUnknownLevel()

    /**
     * registerContact rejects a sub-hour wait period.
     *
     * @return void
     */
    public function testRegisterRejectsZeroWaitPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->registerContact(ownerId: 'alice', contactId: 'bob', waitPeriodHours: 0);
    }//end testRegisterRejectsZeroWaitPeriod()

    /**
     * registerContact creates a pending-confirmation designation with no key
     * material.
     *
     * @return void
     */
    public function testRegisterCreatesPendingDesignation(): void
    {
        $this->contactMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (EmergencyContact $c): EmergencyContact => $c);

        $contact = $this->service->registerContact(
            ownerId: 'alice',
            contactId: 'bob',
            waitPeriodHours: 48,
            level: EmergencyContact::LEVEL_TAKEOVER,
        );

        $this->assertSame('alice', $contact->getOwnerId());
        $this->assertSame('bob', $contact->getContactId());
        $this->assertSame(48, $contact->getWaitPeriodHours());
        $this->assertSame(EmergencyContact::LEVEL_TAKEOVER, $contact->getLevel());
        $this->assertSame(EmergencyContact::STATUS_PENDING_CONFIRMATION, $contact->getStatus());
        $this->assertNull($contact->getWrappedKeyMaterial());
        $this->assertNotEmpty($contact->getId());
    }//end testRegisterCreatesPendingDesignation()

    /**
     * confirmContact stores the wrapped material, activates the row, and is
     * owner-only.
     *
     * @return void
     */
    public function testConfirmStoresWrappedMaterialAndActivates(): void
    {
        $contact = $this->makeContact(ownerId: 'alice', status: EmergencyContact::STATUS_PENDING_CONFIRMATION);
        $this->contactMapper->method('findById')->willReturn($contact);
        $this->contactMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static fn (EmergencyContact $c): EmergencyContact => $c);

        $result = $this->service->confirmContact(
            id: 'ec-1',
            ownerId: 'alice',
            wrappedKeyMaterial: 'base64-ciphertext',
            contactSuiteFingerprint: 'fp-123',
        );

        $this->assertSame(EmergencyContact::STATUS_ACTIVE, $result->getStatus());
        $this->assertSame('base64-ciphertext', $result->getWrappedKeyMaterial());
        $this->assertSame('fp-123', $result->getContactSuiteFingerprint());
        $this->assertNotNull($result->getConfirmedAt());
    }//end testConfirmStoresWrappedMaterialAndActivates()

    /**
     * confirmContact refuses a non-owner (403).
     *
     * @return void
     */
    public function testConfirmRejectsNonOwner(): void
    {
        $contact = $this->makeContact(ownerId: 'alice', status: EmergencyContact::STATUS_PENDING_CONFIRMATION);
        $this->contactMapper->method('findById')->willReturn($contact);

        $this->expectException(InvalidArgumentException::class);
        $this->service->confirmContact(id: 'ec-1', ownerId: 'mallory', wrappedKeyMaterial: 'x');
    }//end testConfirmRejectsNonOwner()

    /**
     * confirmContact is idempotent — re-confirming an active designation
     * refreshes the material and stays active.
     *
     * @return void
     */
    public function testConfirmIsIdempotentOnActive(): void
    {
        $contact = $this->makeContact(ownerId: 'alice', status: EmergencyContact::STATUS_ACTIVE);
        $contact->setWrappedKeyMaterial('old-blob');
        $this->contactMapper->method('findById')->willReturn($contact);
        $this->contactMapper->method('update')->willReturnCallback(static fn (EmergencyContact $c): EmergencyContact => $c);

        $result = $this->service->confirmContact(id: 'ec-1', ownerId: 'alice', wrappedKeyMaterial: 'new-blob');

        $this->assertSame(EmergencyContact::STATUS_ACTIVE, $result->getStatus());
        $this->assertSame('new-blob', $result->getWrappedKeyMaterial());
    }//end testConfirmIsIdempotentOnActive()

    /**
     * requestAccess requires an active designation for the pair (403 when
     * none).
     *
     * @return void
     */
    public function testRequestAccessRequiresActiveDesignation(): void
    {
        $this->contactMapper->method('findActiveForPair')->willThrowException(new DoesNotExistException('none'));

        $this->expectException(InvalidArgumentException::class);
        $this->service->requestAccess(contactId: 'bob', ownerId: 'alice');
    }//end testRequestAccessRequiresActiveDesignation()

    /**
     * requestAccess creates a `requested` row bound to the active
     * designation.
     *
     * @return void
     */
    public function testRequestAccessCreatesRequestedRow(): void
    {
        $contact = $this->makeContact(ownerId: 'alice', status: EmergencyContact::STATUS_ACTIVE);
        $this->contactMapper->method('findActiveForPair')->willReturn($contact);
        $this->requestMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (EmergencyRequest $r): EmergencyRequest => $r);

        $request = $this->service->requestAccess(contactId: 'bob', ownerId: 'alice');

        $this->assertSame(EmergencyRequest::STATUS_REQUESTED, $request->getStatus());
        $this->assertSame('ec-1', $request->getEmergencyContactId());
        $this->assertSame('bob', $request->getContactId());
        $this->assertSame('alice', $request->getOwnerId());
        $this->assertNotNull($request->getRequestedAt());
    }//end testRequestAccessCreatesRequestedRow()

    /**
     * rejectRequest transitions a pending request to rejected (owner-only).
     *
     * @return void
     */
    public function testRejectTransitionsToRejected(): void
    {
        $request = $this->makeRequest(status: EmergencyRequest::STATUS_REQUESTED);
        $this->requestMapper->method('findById')->willReturn($request);
        $this->requestMapper->method('update')->willReturnCallback(static fn (EmergencyRequest $r): EmergencyRequest => $r);

        $result = $this->service->rejectRequest(id: 'req-1', ownerId: 'alice');

        $this->assertSame(EmergencyRequest::STATUS_REJECTED, $result->getStatus());
        $this->assertNotNull($result->getResolvedAt());
    }//end testRejectTransitionsToRejected()

    /**
     * rejectRequest refuses a non-owner (403).
     *
     * @return void
     */
    public function testRejectRejectsNonOwner(): void
    {
        $request = $this->makeRequest(status: EmergencyRequest::STATUS_REQUESTED);
        $this->requestMapper->method('findById')->willReturn($request);

        $this->expectException(InvalidArgumentException::class);
        $this->service->rejectRequest(id: 'req-1', ownerId: 'mallory');
    }//end testRejectRejectsNonOwner()

    /**
     * rejectRequest refuses an already-resolved request (409).
     *
     * @return void
     */
    public function testRejectRejectsNonPending(): void
    {
        $request = $this->makeRequest(status: EmergencyRequest::STATUS_GRANTED);
        $this->requestMapper->method('findById')->willReturn($request);

        $this->expectException(InvalidArgumentException::class);
        $this->service->rejectRequest(id: 'req-1', ownerId: 'alice');
    }//end testRejectRejectsNonPending()

    /**
     * cancelRequest lets the requesting contact self-cancel.
     *
     * @return void
     */
    public function testCancelBySelfTransitionsToCancelled(): void
    {
        $request = $this->makeRequest(status: EmergencyRequest::STATUS_REQUESTED);
        $this->requestMapper->method('findById')->willReturn($request);
        $this->requestMapper->method('update')->willReturnCallback(static fn (EmergencyRequest $r): EmergencyRequest => $r);

        $result = $this->service->cancelRequest(id: 'req-1', contactId: 'bob');

        $this->assertSame(EmergencyRequest::STATUS_CANCELLED, $result->getStatus());
    }//end testCancelBySelfTransitionsToCancelled()

    /**
     * The expiry sweep only grants requests past their wait period; a request
     * still inside its window is left untouched.
     *
     * @return void
     */
    public function testExpirySweepGrantsOnlyExpiredRequests(): void
    {
        // Wait period 24h. "expired" was requested 25h ago; "fresh" 1h ago.
        $expired = $this->makeRequest(status: EmergencyRequest::STATUS_REQUESTED, id: 'req-expired');
        $expired->setRequestedAt((clone $this->now)->modify('-25 hours'));
        $fresh = $this->makeRequest(status: EmergencyRequest::STATUS_REQUESTED, id: 'req-fresh');
        $fresh->setRequestedAt((clone $this->now)->modify('-1 hours'));

        $this->requestMapper->method('findAllRequested')->willReturn([$expired, $fresh]);
        $this->contactMapper->method('findById')->willReturn(
            $this->makeContact(ownerId: 'alice', status: EmergencyContact::STATUS_ACTIVE)
        );

        $updated = [];
        $this->requestMapper->method('update')->willReturnCallback(
            static function (EmergencyRequest $r) use (&$updated): EmergencyRequest {
                $updated[] = $r->getId();
                return $r;
            }
        );

        $granted = $this->service->processExpiredRequests();

        $this->assertSame(1, $granted);
        $this->assertSame([EmergencyRequest::STATUS_GRANTED], [$expired->getStatus()]);
        $this->assertSame(EmergencyRequest::STATUS_REQUESTED, $fresh->getStatus());
        $this->assertSame(['req-expired'], $updated);
    }//end testExpirySweepGrantsOnlyExpiredRequests()

    /**
     * hasGrantedAccess is true only for a granted request naming the contact
     * for that owner.
     *
     * @return void
     */
    public function testHasGrantedAccessReflectsGrantedRows(): void
    {
        $granted = $this->makeRequest(status: EmergencyRequest::STATUS_GRANTED);
        $this->requestMapper->method('findByOwner')->willReturn([$granted]);

        $this->assertTrue($this->service->hasGrantedAccess(contactId: 'bob', ownerId: 'alice'));
        $this->assertFalse($this->service->hasGrantedAccess(contactId: 'mallory', ownerId: 'alice'));
    }//end testHasGrantedAccessReflectsGrantedRows()

    /**
     * Build an EmergencyContact fixture.
     *
     * @param string $ownerId The owner user ID
     * @param string $status  The status
     *
     * @return EmergencyContact
     */
    private function makeContact(string $ownerId, string $status): EmergencyContact
    {
        $contact = new EmergencyContact();
        $contact->setId('ec-1');
        $contact->setOwnerId($ownerId);
        $contact->setContactId('bob');
        $contact->setWaitPeriodHours(24);
        $contact->setLevel(EmergencyContact::LEVEL_VIEW);
        $contact->setStatus($status);
        return $contact;
    }//end makeContact()

    /**
     * Build an EmergencyRequest fixture.
     *
     * @param string $status The status
     * @param string $id     The request ID
     *
     * @return EmergencyRequest
     */
    private function makeRequest(string $status, string $id='req-1'): EmergencyRequest
    {
        $request = new EmergencyRequest();
        $request->setId($id);
        $request->setEmergencyContactId('ec-1');
        $request->setContactId('bob');
        $request->setOwnerId('alice');
        $request->setStatus($status);
        $request->setRequestedAt(clone $this->now);
        return $request;
    }//end makeRequest()
}//end class
