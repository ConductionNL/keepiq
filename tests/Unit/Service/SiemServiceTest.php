<?php

/**
 * Unit tests for SiemService (siem-audit-export §7).
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
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\SiemQueueItem;
use OCA\Doriath\Db\SiemQueueItemMapper;
use OCA\Doriath\Db\SiemSink;
use OCA\Doriath\Db\SiemSinkMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\SiemService;
use OCA\Doriath\Service\SiemSinkService;
use OCA\Doriath\Service\SiemTransport;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SiemService payload building, backpressure, and retry
 * transitions.
 */
class SiemServiceTest extends TestCase
{
    private SiemService $service;

    private SiemSinkMapper&MockObject $sinkMapper;

    private SiemQueueItemMapper&MockObject $queueMapper;

    private ICrypto&MockObject $crypto;

    private IClientService&MockObject $clientService;

    /**
     * Build the service over mocked mappers/transport.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->sinkMapper    = $this->createMock(originalClassName: SiemSinkMapper::class);
        $this->queueMapper   = $this->createMock(originalClassName: SiemQueueItemMapper::class);
        $this->crypto        = $this->createMock(originalClassName: ICrypto::class);
        $this->clientService = $this->createMock(originalClassName: IClientService::class);

        $transport = new SiemTransport(crypto: $this->crypto, clientService: $this->clientService);

        $this->service = new SiemService(
            sinkMapper: $this->sinkMapper,
            queueMapper: $this->queueMapper,
            transport: $transport,
            sinkService: new SiemSinkService(
                sinkMapper: $this->sinkMapper,
                queueMapper: $this->queueMapper,
                crypto: $this->crypto,
                transport: $transport,
            ),
            groupManager: $this->createMock(originalClassName: IGroupManager::class),
            notificationService: null,
            logger: new NullLogger(),
        );
    }//end setUp()

    /**
     * A minimal enabled sink.
     *
     * @param string $type The sink type
     *
     * @return SiemSink
     */
    private function makeSink(string $type='webhook'): SiemSink
    {
        $sink = new SiemSink();
        $sink->setId('sink-1');
        $sink->setName('Test sink');
        $sink->setType($type);
        $sink->setEnabled(true);
        $sink->setEndpoint(($type === 'webhook') ? 'https://siem.example.org/ingest' : 'siem.example.org:6514');
        $sink->setQueueCap(3);

        return $sink;
    }//end makeSink()

    /**
     * buildPayload keeps only whitelisted metadata, drops forbidden and
     * unknown keys, and derives the category from the event-type prefix
     * (§2.1).
     *
     * @return void
     */
    public function testBuildPayloadIsWhitelistedSubset(): void
    {
        $event = AuditEvent::forUser(
            actorId: 'alice',
            eventType: AuditEventTypes::SECRET_CREATED,
            objectType: 'secret',
            objectId: 'secret-1',
            objectName: 'ignored',
            metadata: [
                'typeId'   => 'password',
                'folderId' => 'folder-1',
                'password' => 'MUST-NEVER-APPEAR',
                'random'   => 'not-whitelisted',
            ],
        );

        $payload = $this->service->buildPayload(event: $event);

        $this->assertNotNull($payload);
        $this->assertSame('secret.created', $payload['eventType']);
        $this->assertSame('secret', $payload['category']);
        $this->assertSame('alice', $payload['actorId']);
        $this->assertSame(['typeId' => 'password', 'folderId' => 'folder-1'], $payload['metadata']);
        $this->assertStringNotContainsString('MUST-NEVER-APPEAR', (string) json_encode($payload));
    }//end testBuildPayloadIsWhitelistedSubset()

    /**
     * Unknown event types produce no payload (§2.1).
     *
     * @return void
     */
    public function testBuildPayloadReturnsNullForUnknownType(): void
    {
        $event = AuditEvent::forUser(
            actorId: 'alice',
            eventType: 'not.a_known_type',
            objectType: 'secret',
        );

        $this->assertNull($this->service->buildPayload(event: $event));
    }//end testBuildPayloadReturnsNullForUnknownType()

    /**
     * A sink with a category filter only receives matching categories
     * (§2.3).
     *
     * @return void
     */
    public function testEnqueueRespectsCategoryFilter(): void
    {
        $sink = $this->makeSink();
        $sink->setCategoryFilter((string) json_encode(['share']));
        $this->sinkMapper->method('findEnabled')->willReturn([$sink]);
        $this->queueMapper->expects($this->never())->method('insert');

        $enqueued = $this->service->enqueue(payload: ['category' => 'secret', 'eventType' => 'secret.created']);

        $this->assertSame(0, $enqueued);
    }//end testEnqueueRespectsCategoryFilter()

    /**
     * At the queue cap the oldest pending row is dropped, droppedCount
     * increments, and the new row still lands (§2.3).
     *
     * @return void
     */
    public function testEnqueueDropsOldestAtCap(): void
    {
        $sink = $this->makeSink();
        $this->sinkMapper->method('findEnabled')->willReturn([$sink]);

        // First countPending call reports the cap reached; after one
        // drop the count is below the cap again.
        $this->queueMapper->method('countPending')->willReturnOnConsecutiveCalls(3, 2);
        $oldest = new SiemQueueItem();
        $oldest->setId('old-1');
        $this->queueMapper->method('oldestPending')->willReturn($oldest);
        $this->queueMapper->expects($this->once())->method('delete')->with($oldest);
        $this->queueMapper->expects($this->once())->method('insert');

        $enqueued = $this->service->enqueue(payload: ['category' => 'secret', 'eventType' => 'secret.created']);

        $this->assertSame(1, $enqueued);
        $this->assertSame(1, $sink->getDroppedCount());
    }//end testEnqueueDropsOldestAtCap()

    /**
     * A failed delivery advances attempts, schedules a backoff, and
     * marks the sink failing (§4.2).
     *
     * @return void
     */
    public function testDeliverOneAppliesBackoffOnFailure(): void
    {
        $sink = $this->makeSink();
        $this->clientService->method('newClient')->willThrowException(new \RuntimeException('down'));

        $item = new SiemQueueItem();
        $item->setId('item-1');
        $item->setSinkId('sink-1');
        $item->setPayload('{}');
        $item->setStatus('pending');

        $ok = $this->service->deliverOne(sink: $sink, item: $item);

        $this->assertFalse($ok);
        $this->assertSame(1, $item->getAttempts());
        $this->assertSame('pending', $item->getStatus());
        $this->assertNotNull($item->getNextAttemptAt());
        $this->assertSame('failing', $sink->getLastDeliveryStatus());
        $this->assertSame(1, $sink->getConsecutiveFailures());
    }//end testDeliverOneAppliesBackoffOnFailure()

    /**
     * The final failed attempt dead-letters the row and marks the sink
     * dead (§4.2).
     *
     * @return void
     */
    public function testDeliverOneDeadLettersAtMaxAttempts(): void
    {
        $sink = $this->makeSink();
        $this->clientService->method('newClient')->willThrowException(new \RuntimeException('down'));

        $item = new SiemQueueItem();
        $item->setId('item-1');
        $item->setSinkId('sink-1');
        $item->setPayload('{}');
        $item->setStatus('pending');
        $item->setAttempts(SiemService::MAX_ATTEMPTS - 1);

        $ok = $this->service->deliverOne(sink: $sink, item: $item);

        $this->assertFalse($ok);
        $this->assertSame('dead', $item->getStatus());
        $this->assertSame('dead', $sink->getLastDeliveryStatus());
    }//end testDeliverOneDeadLettersAtMaxAttempts()

    /**
     * Webhook sinks must use https endpoints (§3.2).
     *
     * @return void
     */
    public function testCreateSinkRejectsPlainHttpWebhook(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createSink(
            adminUid: 'admin',
            params: [
                'type'     => 'webhook',
                'endpoint' => 'http://siem.example.org/ingest',
            ],
        );
    }//end testCreateSinkRejectsPlainHttpWebhook()

    /**
     * A blank hmacSecret on update preserves the stored encrypted secret
     * (§3.2 write-only semantics).
     *
     * @return void
     */
    public function testUpdateSinkBlankSecretPreservesStored(): void
    {
        $sink = $this->makeSink();
        $sink->setHmacSecretEnc('encrypted-old');
        $this->sinkMapper->method('findById')->willReturn($sink);
        $this->sinkMapper->method('update')->willReturnArgument(0);
        $this->crypto->expects($this->never())->method('encrypt');

        $updated = $this->service->updateSink(
            adminUid: 'admin',
            sinkId: 'sink-1',
            params: [
                'name'       => 'Renamed',
                'hmacSecret' => '',
            ],
        );

        $this->assertSame('encrypted-old', $updated->getHmacSecretEnc());
        $this->assertSame('Renamed', $updated->getName());
        $this->assertTrue($updated->jsonSerialize()['hasHmacSecret']);
        $this->assertArrayNotHasKey('hmacSecretEnc', $updated->jsonSerialize());
    }//end testUpdateSinkBlankSecretPreservesStored()
}//end class
