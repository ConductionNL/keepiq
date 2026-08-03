<?php

/**
 * Unit tests for AuditService.
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

use OCA\Doriath\Db\AuditEntry;
use OCA\Doriath\Db\AuditEntryMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Exception\AuditForbiddenMetadataException;
use OCA\Doriath\Service\AuditService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditService — the single write path + query API.
 */
class AuditServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var AuditService
     */
    private AuditService $service;

    /**
     * The mocked audit-entry mapper.
     *
     * @var AuditEntryMapper&MockObject
     */
    private AuditEntryMapper $mapper;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper  = $this->createMock(originalClassName: AuditEntryMapper::class);
        $this->service = new AuditService(mapper: $this->mapper);
    }//end setUp()

    /**
     * record() persists an entry through the mapper with the event fields.
     *
     * @return void
     */
    public function testRecordPersistsEntry(): void
    {
        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                function (AuditEntry $entry) use (&$captured): AuditEntry {
                    $captured = $entry;
                    return $entry;
                }
            );

        $event = AuditEvent::forUser(
            actorId: 'alice',
            eventType: AuditEventTypes::SECRET_UPDATED,
            objectType: 'secret',
            objectId: 'sec-1',
            objectName: 'GitHub deploy key',
            metadata: ['changedFields' => ['name']],
        );

        $this->service->record($event);

        $this->assertNotNull($captured);
        $this->assertSame('alice', $captured->getActorId());
        $this->assertSame('user', $captured->getActorType());
        $this->assertSame('secret.updated', $captured->getEventType());
        $this->assertSame('sec-1', $captured->getObjectId());
        $this->assertSame('GitHub deploy key', $captured->getObjectName());
        $this->assertSame(['changedFields' => ['name']], $captured->getMetadataArray());
    }//end testRecordPersistsEntry()

    /**
     * record() drops metadata keys not on the per-event-type whitelist.
     *
     * @return void
     */
    public function testRecordDropsUnknownMetadataKeys(): void
    {
        $captured = null;
        $this->mapper->method('insert')->willReturnCallback(
            function (AuditEntry $entry) use (&$captured): AuditEntry {
                $captured = $entry;
                return $entry;
            }
        );

        // share.granted whitelist = {recipientType, recipientId}; "note" is unknown.
        $event = AuditEvent::forUser(
            actorId: 'alice',
            eventType: AuditEventTypes::SHARE_GRANTED,
            objectType: 'share',
            objectId: 'sh-1',
            objectName: null,
            metadata: ['recipientType' => 'user', 'recipientId' => 'bob', 'note' => 'leak me'],
        );

        $this->service->record($event);

        $meta = $captured->getMetadataArray();
        $this->assertArrayHasKey('recipientType', $meta);
        $this->assertArrayHasKey('recipientId', $meta);
        $this->assertArrayNotHasKey('note', $meta);
    }//end testRecordDropsUnknownMetadataKeys()

    /**
     * record() rejects every forbidden secret-material key with an exception.
     *
     * @return void
     */
    public function testRecordRejectsEveryForbiddenKey(): void
    {
        foreach (AuditEventTypes::FORBIDDEN_KEYS as $forbidden) {
            $event = AuditEvent::forUser(
                actorId: 'alice',
                eventType: AuditEventTypes::SECRET_CREATED,
                objectType: 'secret',
                objectId: 'sec-1',
                objectName: 'X',
                metadata: [$forbidden => 'super-secret-value'],
            );

            $threw = false;
            try {
                $this->service->record($event);
            } catch (AuditForbiddenMetadataException) {
                $threw = true;
            }

            $this->assertTrue($threw, "Forbidden key '{$forbidden}' must be rejected");
        }
    }//end testRecordRejectsEveryForbiddenKey()

    /**
     * record() rejects a forbidden key nested inside a metadata array.
     *
     * @return void
     */
    public function testRecordRejectsNestedForbiddenKey(): void
    {
        $this->expectException(AuditForbiddenMetadataException::class);

        $event = AuditEvent::forUser(
            actorId: 'alice',
            eventType: AuditEventTypes::SECRET_CREATED,
            objectType: 'secret',
            objectId: 'sec-1',
            objectName: 'X',
            metadata: ['changedFields' => ['nested' => ['password' => 'leak']]],
        );

        $this->service->record($event);
    }//end testRecordRejectsNestedForbiddenKey()

    /**
     * purge() loops batches and sums the deletions, using the retention window.
     *
     * @return void
     */
    public function testPurgeLoopsBatches(): void
    {
        // First batch returns a full batch (1000), second a partial (12) → stop.
        $this->mapper->expects($this->exactly(2))
            ->method('purgeOlderThan')
            ->willReturnOnConsecutiveCalls(1000, 12);

        $total = $this->service->purge(retentionDays: 365, batchSize: 1000);

        $this->assertSame(1012, $total);
    }//end testPurgeLoopsBatches()

    /**
     * anonymizeUser() replaces the actor and scrubs user-referencing metadata.
     *
     * @return void
     */
    public function testAnonymizeUserScrubsActorAndMetadata(): void
    {
        $this->mapper->expects($this->once())
            ->method('anonymizeActor')
            ->with('bob')
            ->willReturn(3);

        $referencing = new AuditEntry();
        $referencing->setId(7);
        $referencing->setEventType(AuditEventTypes::SHARE_GRANTED);
        $referencing->setMetadata((string) json_encode(['recipientType' => 'user', 'recipientId' => 'bob']));

        $this->mapper->method('findMetadataReferencing')->with('bob')->willReturn([$referencing]);

        $rewritten = null;
        $this->mapper->expects($this->once())
            ->method('rewriteMetadata')
            ->willReturnCallback(
                function (int $id, ?string $json) use (&$rewritten): void {
                    $rewritten = json_decode((string) $json, true);
                }
            );

        $this->service->anonymizeUser('bob');

        $this->assertSame('deleted-account', $rewritten['recipientId']);
        $this->assertSame('user', $rewritten['recipientType']);
        $this->assertStringNotContainsString('bob', (string) json_encode($rewritten));
    }//end testAnonymizeUserScrubsActorAndMetadata()

    /**
     * adminQuery() returns entries + total + clamps page/limit.
     *
     * @return void
     */
    public function testAdminQueryReturnsEntriesAndTotal(): void
    {
        $entry = new AuditEntry();
        $entry->setEventType(AuditEventTypes::SECRET_READ);

        $this->mapper->method('findFiltered')->willReturn([$entry]);
        $this->mapper->method('countFiltered')->willReturn(137);

        $result = $this->service->adminQuery(['eventType' => 'secret.read'], 2, 50);

        $this->assertSame(137, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(50, $result['limit']);
        $this->assertCount(1, $result['entries']);
    }//end testAdminQueryReturnsEntriesAndTotal()
}//end class
