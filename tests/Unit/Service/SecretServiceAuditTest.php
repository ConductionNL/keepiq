<?php

/**
 * Unit tests for SecretService audit-event dispatch.
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

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\SecretTypeService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that SecretService emits secret.* audit events at the right sites and
 * NOT on list/search (audit-trail §3.1, §7.6).
 */
class SecretServiceAuditTest extends TestCase
{
    /**
     * The mocked secret mapper.
     *
     * @var SecretMapper&MockObject
     */
    private SecretMapper $mapper;

    /**
     * The mocked suite mapper.
     *
     * @var EncryptionSuiteMapper&MockObject
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * The mocked event dispatcher.
     *
     * @var IEventDispatcher&MockObject
     */
    private IEventDispatcher $dispatcher;

    /**
     * The service under test.
     *
     * @var SecretService
     */
    private SecretService $service;

    /**
     * Set up test fixtures with a wired event dispatcher.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper          = $this->createMock(SecretMapper::class);
        $this->suiteMapper     = $this->createMock(EncryptionSuiteMapper::class);
        $this->dispatcher      = $this->createMock(IEventDispatcher::class);
        $typeService           = $this->createMock(SecretTypeService::class);
        $migrationService      = $this->createMock(MigrationService::class);
        $linkShareService      = $this->createMock(LinkShareService::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $migrationService->method('isWriteLocked')->willReturn(false);

        $this->service = new SecretService(
            mapper: $this->mapper,
            typeService: $typeService,
            suiteMapper: $this->suiteMapper,
            migrationService: $migrationService,
            linkShareService: $linkShareService,
            logger: $logger,
            secretRequestService: null,
            shareService: null,
            groupShareMapper: null,
            secretDelegationMapper: null,
            eventDispatcher: $this->dispatcher,
        );
    }//end setUp()

    /**
     * Build a user-owned secret.
     *
     * @return Secret
     */
    private function makeSecret(): Secret
    {
        $secret = new Secret();
        $secret->setId('s-1');
        $secret->setName('GitHub');
        $secret->setKey('CIPHERTEXT');
        $secret->setEncryptionSuiteId('suite-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        return $secret;
    }//end makeSecret()

    /**
     * Build an active suite.
     *
     * @return EncryptionSuite
     */
    private function makeSuite(): EncryptionSuite
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('alice');
        $suite->setStatus('active');
        return $suite;
    }//end makeSuite()

    /**
     * An individual get() emits exactly one secret.read event.
     *
     * @return void
     */
    public function testGetEmitsExactlyOneSecretRead(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret());
        $this->suiteMapper->method('findById')->willReturn($this->makeSuite());

        $events = [];
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(
            function (AuditEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $this->service->get(id: 's-1', userId: 'alice');

        $reads = array_filter(
            $events,
            static fn(AuditEvent $e): bool => $e->getEventType() === AuditEventTypes::SECRET_READ
        );
        $this->assertCount(1, $reads);
        $read = array_values($reads)[0];
        $this->assertSame('alice', $read->getActorId());
        $this->assertSame('s-1', $read->getObjectId());
        $this->assertSame('GitHub', $read->getObjectName());
    }//end testGetEmitsExactlyOneSecretRead()

    /**
     * list() emits NO secret.read events (audit-trail §3.1).
     *
     * @return void
     */
    public function testListEmitsNoSecretRead(): void
    {
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->suiteMapper->method('findById')->willReturn($this->makeSuite());

        $events = [];
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(
            function (AuditEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $this->service->list(
            userId: 'alice',
            folderId: null,
            sort: 'name',
            direction: 'asc',
            page: 1,
            limit: 50,
        );

        $reads = array_filter(
            $events,
            static fn(AuditEvent $e): bool => $e->getEventType() === AuditEventTypes::SECRET_READ
        );
        $this->assertCount(0, $reads);
    }//end testListEmitsNoSecretRead()

    /**
     * create() emits a secret.created event with the secret id + name.
     *
     * @return void
     */
    public function testCreateEmitsSecretCreated(): void
    {
        $this->suiteMapper->method('findActiveByOwner')->willReturn($this->makeSuite());

        $events = [];
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(
            function (AuditEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $this->service->create(
            data: ['name' => 'New', 'key' => 'CIPHER'],
            userId: 'alice',
        );

        $created = array_filter(
            $events,
            static fn(AuditEvent $e): bool => $e->getEventType() === AuditEventTypes::SECRET_CREATED
        );
        $this->assertCount(1, $created);
        $this->assertSame('alice', array_values($created)[0]->getActorId());
    }//end testCreateEmitsSecretCreated()
}//end class
