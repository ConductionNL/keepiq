<?php

/**
 * Unit tests for HoneyCredentialService (honey-credentials §6).
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

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\HoneyAlert;
use OCA\Doriath\Db\HoneyAlertMapper;
use OCA\Doriath\Db\HoneyFlag;
use OCA\Doriath\Db\HoneyFlagMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Service\HoneyCredentialService;
use OCA\Doriath\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the honey tripwire service.
 */
class HoneyCredentialServiceTest extends TestCase
{
    private HoneyCredentialService $service;

    private HoneyFlagMapper&MockObject $flagMapper;

    private HoneyAlertMapper&MockObject $alertMapper;

    private SecretMapper&MockObject $secretMapper;

    private NotificationService&MockObject $notificationService;

    private IEventDispatcher&MockObject $eventDispatcher;

    /**
     * Build the service over mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->flagMapper          = $this->createMock(originalClassName: HoneyFlagMapper::class);
        $this->alertMapper         = $this->createMock(originalClassName: HoneyAlertMapper::class);
        $this->secretMapper        = $this->createMock(originalClassName: SecretMapper::class);
        $this->notificationService = $this->createMock(originalClassName: NotificationService::class);
        $this->eventDispatcher     = $this->createMock(originalClassName: IEventDispatcher::class);

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default=0): int => $default
        );

        $groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $groupManager->method('get')->willReturn(null);

        $this->service = new HoneyCredentialService(
            flagMapper: $this->flagMapper,
            alertMapper: $this->alertMapper,
            secretMapper: $this->secretMapper,
            groupManager: $groupManager,
            appConfig: $appConfig,
            notificationService: $this->notificationService,
            logger: new NullLogger(),
            eventDispatcher: $this->eventDispatcher,
        );
    }//end setUp()

    /**
     * A secret owned by alice.
     *
     * @return Secret
     */
    private function makeSecret(): Secret
    {
        $secret = new Secret();
        $secret->setId('secret-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        $secret->setName('decoy');

        return $secret;
    }//end makeSecret()

    /**
     * A flag on secret-1 owned by alice.
     *
     * @return HoneyFlag
     */
    private function makeFlag(): HoneyFlag
    {
        $flag = new HoneyFlag();
        $flag->setId('flag-1');
        $flag->setSecretId('secret-1');
        $flag->setOwnerId('alice');

        return $flag;
    }//end makeFlag()

    /**
     * Flagging is owner-or-admin only (§6.1).
     *
     * @return void
     */
    public function testFlagRejectsNonOwnerNonAdmin(): void
    {
        $this->secretMapper->method('findById')->willReturn($this->makeSecret());
        $this->flagMapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->service->flag(secretId: 'secret-1', actorId: 'mallory', isAdmin: false);
    }//end testFlagRejectsNonOwnerNonAdmin()

    /**
     * The owner can flag; the flag row never touches the secret row —
     * and Secret::jsonSerialize carries no honey marker, so recipients
     * cannot distinguish a decoy (§6.1 / §2.4).
     *
     * @return void
     */
    public function testFlagByOwnerAndSecretShapeUnchanged(): void
    {
        $secret = $this->makeSecret();
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->flagMapper->method('findBySecretId')->willThrowException(new DoesNotExistException('none'));
        $this->flagMapper->expects($this->once())->method('insert')->willReturnArgument(0);
        $this->secretMapper->expects($this->never())->method('update');

        $flag = $this->service->flag(secretId: 'secret-1', actorId: 'alice', isAdmin: false, note: 'prod bait');

        $this->assertSame('alice', $flag->getOwnerId());
        $this->assertSame('prod bait', $flag->getNote());

        $serialized = (string) json_encode($secret->jsonSerialize());
        $this->assertStringNotContainsStringIgnoringCase('honey', $serialized);
    }//end testFlagByOwnerAndSecretShapeUnchanged()

    /**
     * A first access raises an alert, pages, and audits with channel
     * only — no secret material anywhere (§6.1/§6.2).
     *
     * @return void
     */
    public function testRaiseAlertPagesAndAudits(): void
    {
        $this->flagMapper->method('findBySecretId')->willReturn($this->makeFlag());
        $this->alertMapper->method('findLatestForAccessor')->willReturn(null);

        $inserted = null;
        $this->alertMapper->expects($this->once())->method('insert')
            ->willReturnCallback(static function (HoneyAlert $alert) use (&$inserted) {
                $inserted = $alert;

                return $alert;
            });
        $this->notificationService->expects($this->once())->method('notify')
            ->with($this->equalTo('honey_access'), $this->equalTo('alice'));
        $this->eventDispatcher->expects($this->once())->method('dispatchTyped')
            ->with($this->callback(static function (AuditEvent $event): bool {
                return $event->getEventType() === AuditEventTypes::HONEY_ACCESSED
                    && $event->getMetadata() === ['channel' => 'machine_api'];
            }));

        $hit = $this->service->raiseAlert(
            secretId: 'secret-1',
            accessorType: 'application',
            accessorId: 'app-9',
            channel: 'machine_api',
            ip: '10.0.0.9',
            userAgent: 'curl/8',
        );

        $this->assertTrue($hit);
        $this->assertSame('machine_api', $inserted->getChannel());
        $this->assertSame('10.0.0.9', $inserted->getIp());
        // The alert shape carries access metadata ONLY — never secret fields.
        $alertKeys = array_keys($inserted->jsonSerialize());
        foreach (['key', 'login', 'value', 'additionalFields', 'ciphertext', 'payload'] as $forbidden) {
            $this->assertNotContains($forbidden, $alertKeys);
        }
    }//end testRaiseAlertPagesAndAudits()

    /**
     * A repeat by the same accessor inside the window collapses into
     * the existing alert — no second page, but still audited (§6.2).
     *
     * @return void
     */
    public function testRaiseAlertDedupsWithinWindow(): void
    {
        $existing = new HoneyAlert();
        $existing->setId('alert-1');
        $existing->setAccessCount(1);
        $existing->setAccessedAt(new DateTime('-5 minutes'));

        $this->flagMapper->method('findBySecretId')->willReturn($this->makeFlag());
        $this->alertMapper->method('findLatestForAccessor')->willReturn($existing);
        $this->alertMapper->expects($this->never())->method('insert');
        $this->alertMapper->expects($this->once())->method('update')->willReturnArgument(0);
        $this->notificationService->expects($this->never())->method('notify');
        $this->eventDispatcher->expects($this->once())->method('dispatchTyped');

        $this->service->raiseAlert(secretId: 'secret-1', accessorType: 'user', accessorId: 'bob', channel: 'ui');

        $this->assertSame(2, $existing->getAccessCount());
    }//end testRaiseAlertDedupsWithinWindow()

    /**
     * A snoozed accessor never pages but IS still audited (§6.2).
     *
     * @return void
     */
    public function testSnoozedAccessorIsAuditedButNotPaged(): void
    {
        $existing = new HoneyAlert();
        $existing->setId('alert-1');
        $existing->setAccessCount(3);
        $existing->setAccessedAt(new DateTime('-2 days'));
        $existing->setSnoozedUntil(new DateTime('+1 hour'));

        $this->flagMapper->method('findBySecretId')->willReturn($this->makeFlag());
        $this->alertMapper->method('findLatestForAccessor')->willReturn($existing);
        $this->alertMapper->expects($this->never())->method('insert');
        $this->alertMapper->expects($this->once())->method('update')->willReturnArgument(0);
        $this->notificationService->expects($this->never())->method('notify');
        $this->eventDispatcher->expects($this->once())->method('dispatchTyped');

        $this->service->raiseAlert(secretId: 'secret-1', accessorType: 'user', accessorId: 'bob', channel: 'ui');
    }//end testSnoozedAccessorIsAuditedButNotPaged()

    /**
     * An unflagged secret is a miss — nothing is written (§6.1).
     *
     * @return void
     */
    public function testRaiseAlertMissOnUnflaggedSecret(): void
    {
        $this->flagMapper->method('findBySecretId')->willThrowException(new DoesNotExistException('none'));
        $this->alertMapper->expects($this->never())->method('insert');
        $this->notificationService->expects($this->never())->method('notify');
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');

        $hit = $this->service->raiseAlert(secretId: 'other', accessorType: 'user', accessorId: 'bob', channel: 'ui');

        $this->assertFalse($hit);
    }//end testRaiseAlertMissOnUnflaggedSecret()

    /**
     * The tripwire is fail-soft: an alert-write failure is swallowed
     * and never propagates into the observed access (§6.2).
     *
     * @return void
     */
    public function testRaiseAlertIsFailSoft(): void
    {
        $this->flagMapper->method('findBySecretId')->willReturn($this->makeFlag());
        $this->alertMapper->method('findLatestForAccessor')->willThrowException(new \RuntimeException('db down'));

        $hit = $this->service->raiseAlert(secretId: 'secret-1', accessorType: 'user', accessorId: 'bob', channel: 'ui');

        $this->assertTrue($hit, 'the hit is still reported; the failure is logged, not thrown');
    }//end testRaiseAlertIsFailSoft()

    /**
     * Alert actions are guarded: a stranger may neither acknowledge nor
     * snooze someone else's alert (§6.1).
     *
     * @return void
     */
    public function testAcknowledgeRejectsStranger(): void
    {
        $alert = new HoneyAlert();
        $alert->setId('alert-1');
        $alert->setSecretId('secret-1');
        $this->alertMapper->method('findById')->willReturn($alert);
        $this->flagMapper->method('findBySecretId')->willReturn($this->makeFlag());
        $this->alertMapper->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->service->acknowledge(alertId: 'alert-1', actorId: 'mallory', isAdmin: false);
    }//end testAcknowledgeRejectsStranger()

    /**
     * Snooze sets the per-accessor watermark for the owner (§6.2).
     *
     * @return void
     */
    public function testSnoozeSetsWatermark(): void
    {
        $alert = new HoneyAlert();
        $alert->setId('alert-1');
        $alert->setSecretId('secret-1');
        $this->alertMapper->method('findById')->willReturn($alert);
        $this->flagMapper->method('findBySecretId')->willReturn($this->makeFlag());
        $this->alertMapper->method('update')->willReturnArgument(0);

        $updated = $this->service->snooze(alertId: 'alert-1', actorId: 'alice', isAdmin: false, hours: 24);

        $this->assertNotNull($updated->getSnoozedUntil());
        $this->assertGreaterThan(new DateTime('+23 hours'), $updated->getSnoozedUntil());
    }//end testSnoozeSetsWatermark()
}//end class
