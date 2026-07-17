<?php

/**
 * Unit tests for cross-app audit persistence of the SecretService in-process
 * application-vault seam (doriath#54).
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\AuditService;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Service\SecretRequestService;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\SecretTypeService;
use OCA\Doriath\Service\ShareService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reproduces the doriath#54 cross-app resolution context: OpenRegister (or any
 * same-instance app) resolves SecretService via the DI container WITHOUT
 * booting Doriath's frontend Application::register, so the event dispatcher
 * carries NO AuditListener. The service is wired the way that container builds
 * it — the AuditService recorder autowired (its AuditEntryMapper has strictly
 * simpler dependencies than SecretMapper, which the same container already
 * built), plus a listener-less dispatcher. The seam audit events MUST still be
 * persisted through the single append-only write path (AuditService::record →
 * AuditEntryMapper::insert), and MUST NOT be double-written by also hitting the
 * event bus.
 *
 * Spec: application-secret-delete/specs/secrets/spec.md
 * ("exactly one audit entry MUST be recorded ...").
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One test per spec scenario.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The seam legitimately
 *   coordinates the secret mapper, cascade collaborators, the recorder, and
 *   the (listener-less) dispatcher.
 */
class SecretServiceCrossAppAuditTest extends TestCase
{

    /**
     * The mocked secret mapper.
     *
     * @var SecretMapper&MockObject
     */
    private SecretMapper $mapper;

    /**
     * The mocked audit-entry mapper — the real single write path's DB seam.
     *
     * @var AuditEntryMapper&MockObject
     */
    private AuditEntryMapper $auditMapper;

    /**
     * The real audit recorder under the direct-record path (mock mapper).
     *
     * @var AuditService
     */
    private AuditService $auditService;

    /**
     * A listener-less dispatcher — the cross-app request never registered the
     * AuditListener. It MUST NOT be used when the recorder is present.
     *
     * @var IEventDispatcher&MockObject
     */
    private IEventDispatcher $dispatcher;

    /**
     * The mocked link-share service (cascade).
     *
     * @var LinkShareService&MockObject
     */
    private LinkShareService $linkShareService;

    /**
     * The audit entries captured at the AuditEntryMapper::insert seam.
     *
     * @var AuditEntry[]
     */
    private array $inserted = [];

    /**
     * The service under test, wired as a cross-app DI container builds it.
     *
     * @var SecretService
     */
    private SecretService $service;

    /**
     * Wire the service the way a cross-app container resolves it: the
     * AuditService recorder present, the event dispatcher present but carrying
     * no AuditListener. Capture every row that reaches the mapper insert seam.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper           = $this->createMock(SecretMapper::class);
        $this->auditMapper      = $this->createMock(AuditEntryMapper::class);
        $this->linkShareService = $this->createMock(LinkShareService::class);
        $this->dispatcher       = $this->createMock(IEventDispatcher::class);

        $this->inserted = [];
        $this->auditMapper->method('insert')->willReturnCallback(
            function (AuditEntry $entry): AuditEntry {
                $this->inserted[] = $entry;
                return $entry;
            }
        );

        $this->auditService = new AuditService(
            mapper: $this->auditMapper,
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->service = new SecretService(
            mapper: $this->mapper,
            typeService: $this->createMock(SecretTypeService::class),
            suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
            migrationService: $this->createMock(MigrationService::class),
            linkShareService: $this->linkShareService,
            logger: $this->createMock(LoggerInterface::class),
            secretRequestService: $this->createMock(SecretRequestService::class),
            shareService: $this->createMock(ShareService::class),
            groupShareMapper: $this->createMock(GroupShareMapper::class),
            secretDelegationMapper: $this->createMock(SecretDelegationMapper::class),
            eventDispatcher: $this->dispatcher,
            auditService: $this->auditService,
        );
    }//end setUp()

    /**
     * Build a secret owned by the given application vault.
     *
     * @param string $id      The secret id
     * @param string $ownerId The owning application id
     * @param string $name    The plaintext name
     *
     * @return Secret
     */
    private function makeSecret(
        string $id='s-1',
        string $ownerId='app-1',
        string $name='00000000-0000-0000-0000-000000000000',
    ): Secret {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName($name);
        $secret->setKey('KEY-CIPHERTEXT');
        $secret->setLogin('LOGIN-CIPHERTEXT');
        $secret->setAdditionalFields('AF-CIPHERTEXT');
        $secret->setOwnerType('application');
        $secret->setOwnerId($ownerId);
        $secret->setEncryptionSuiteId('suite-1');
        return $secret;
    }//end makeSecret()

    /**
     * A single-match read persists exactly one application.secret_retrieved row
     * through the recorder even though no AuditListener is registered — and the
     * event bus is never touched (no double-write).
     *
     * @return void
     */
    public function testGetByNameForApplicationPersistsAuditRowCrossApp(): void
    {
        $secret = $this->makeSecret();
        $this->mapper->method('findByName')->willReturn([$secret]);
        // No AuditListener in this request: the bus MUST NOT be used.
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $result = $this->service->getByNameForApplication(
            name: $secret->getName(),
            applicationId: 'app-1'
        );

        $this->assertSame($secret, $result);
        $this->assertCount(1, $this->inserted);
        $row = $this->inserted[0];
        $this->assertSame(AuditEvent::ACTOR_APPLICATION, $row->getActorType());
        $this->assertSame('app-1', $row->getActorId());
        $this->assertSame(AuditEventTypes::APPLICATION_SECRET_RETRIEVED, $row->getEventType());
        $this->assertSame('secret', $row->getObjectType());
        $this->assertSame('s-1', $row->getObjectId());
        $this->assertSame($secret->getName(), $row->getObjectName());
    }//end testGetByNameForApplicationPersistsAuditRowCrossApp()

    /**
     * A real own-vault delete persists exactly one secret.deleted row through
     * the recorder cross-app, and never touches the (listener-less) bus.
     *
     * @return void
     */
    public function testDeleteByApplicationPersistsAuditRowCrossApp(): void
    {
        $secret = $this->makeSecret();
        $this->mapper->method('findById')->willReturn($secret);
        $this->mapper->expects($this->once())->method('delete')
            ->with($this->identicalTo($secret));
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

        $this->assertCount(1, $this->inserted);
        $row = $this->inserted[0];
        $this->assertSame(AuditEvent::ACTOR_APPLICATION, $row->getActorType());
        $this->assertSame('app-1', $row->getActorId());
        $this->assertSame(AuditEventTypes::SECRET_DELETED, $row->getEventType());
        $this->assertSame('secret', $row->getObjectType());
        $this->assertSame('s-1', $row->getObjectId());
        $this->assertSame($secret->getName(), $row->getObjectName());
    }//end testDeleteByApplicationPersistsAuditRowCrossApp()

    /**
     * No double-write: when the recorder is wired AND the dispatcher is present
     * (the in-app shape, where an AuditListener would also be registered on the
     * bus), a single seam read produces exactly ONE persisted row and ZERO bus
     * dispatches — so a registered listener can never write a second row.
     *
     * @return void
     */
    public function testRecorderPathDoesNotDoubleWriteViaBus(): void
    {
        $secret = $this->makeSecret();
        $this->mapper->method('findById')->willReturn($secret);
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

        $this->assertCount(1, $this->inserted);
        $this->assertSame(AuditEventTypes::SECRET_DELETED, $this->inserted[0]->getEventType());
    }//end testRecorderPathDoesNotDoubleWriteViaBus()

    /**
     * A read miss (null outcome) records nothing — audit parity: no row, no
     * dispatch — even with the recorder wired.
     *
     * @return void
     */
    public function testMissReadPersistsNoAuditRow(): void
    {
        $this->mapper->method('findByName')->willReturn([]);
        $this->dispatcher->expects($this->never())->method('dispatchTyped');
        $this->auditMapper->expects($this->never())->method('insert');

        $result = $this->service->getByNameForApplication(
            name: 'not-there',
            applicationId: 'app-1'
        );

        $this->assertNull($result);
        $this->assertCount(0, $this->inserted);
    }//end testMissReadPersistsNoAuditRow()

    /**
     * A cross-vault delete (owned by another application) is a silent no-op:
     * nothing deleted and no audit row persisted.
     *
     * @return void
     */
    public function testCrossVaultDeletePersistsNoAuditRow(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret(ownerId: 'app-2'));
        $this->mapper->expects($this->never())->method('delete');
        $this->linkShareService->expects($this->never())->method('deleteBySecretId');
        $this->dispatcher->expects($this->never())->method('dispatchTyped');
        $this->auditMapper->expects($this->never())->method('insert');

        $this->service->deleteByApplication(secretId: 's-1', applicationId: 'app-1');

        $this->assertCount(0, $this->inserted);
    }//end testCrossVaultDeletePersistsNoAuditRow()
}//end class
