<?php

/**
 * Unit tests for AccountDeletionService — the GDPR Art. 17 cascade.
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

use OCA\Doriath\Db\DashboardSettingMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Event\AccountDataDeletedEvent;
use OCA\Doriath\Service\AccountDeletionService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests per cascade step + idempotency + event emission.
 */
class AccountDeletionServiceTest extends TestCase
{

    /**
     * Collaborator mocks, keyed for per-test wiring.
     *
     * @var array<string,object>
     */
    private array $m = [];

    /**
     * Build the service with fresh mocks.
     *
     * @return AccountDeletionService
     */
    private function build(): AccountDeletionService
    {
        $this->m = [
            'secret'     => $this->createMock(SecretMapper::class),
            'folder'     => $this->createMock(FolderMapper::class),
            'share'      => $this->createMock(ShareTargetMapper::class),
            'group'      => $this->createMock(GroupShareMapper::class),
            'delegation' => $this->createMock(SecretDelegationMapper::class),
            'link'       => $this->createMock(LinkShareMapper::class),
            'request'    => $this->createMock(SecretRequestMapper::class),
            'suite'      => $this->createMock(EncryptionSuiteMapper::class),
            'migration'  => $this->createMock(SuiteMigrationMapper::class),
            'setting'    => $this->createMock(DashboardSettingMapper::class),
            'dispatcher' => $this->createMock(IEventDispatcher::class),
        ];

        return new AccountDeletionService(
            secretMapper: $this->m['secret'],
            folderMapper: $this->m['folder'],
            shareMapper: $this->m['share'],
            groupShareMapper: $this->m['group'],
            delegationMapper: $this->m['delegation'],
            linkShareMapper: $this->m['link'],
            requestMapper: $this->m['request'],
            suiteMapper: $this->m['suite'],
            migrationMapper: $this->m['migration'],
            settingMapper: $this->m['setting'],
            dispatcher: $this->m['dispatcher'],
        );
    }//end build()

    /**
     * A Secret owned by a user.
     *
     * @param string $id    The secret ID
     * @param string $owner The owner ID
     *
     * @return Secret
     */
    private function secret(string $id, string $owner='alice'): Secret
    {
        $s = new Secret();
        $s->setId($id);
        $s->setOwnerType('user');
        $s->setOwnerId($owner);
        return $s;
    }//end secret()

    /**
     * Stub the mappers to return nothing (empty vault baseline).
     *
     * @return void
     */
    private function emptyBaseline(): void
    {
        $this->m['secret']->method('findByOwner')->willReturn([]);
        $this->m['delegation']->method('findByOriginalOwner')->willReturn([]);
        $this->m['share']->method('findByTargetUser')->willReturn([]);
        $this->m['link']->method('findByCreatedBy')->willReturn([]);
        $this->m['suite']->method('findByOwner')->willReturn([]);
        $this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['request']->method('deleteByCreatedBy')->willReturn(0);
        $this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['migration']->method('deleteBySuiteIds')->willReturn(0);
    }//end emptyBaseline()

    /**
     * Step 1: a delegated secret transfers ownership + delegation made permanent.
     *
     * @return void
     */
    public function testDelegatedSecretTransfersOwnership(): void
    {
        $service = $this->build();
        $secret  = $this->secret('sec-1');
        $this->m['secret']->method('findByOwner')->willReturn([$secret]);

        $delegation = new SecretDelegation();
        $delegation->setSecretId('sec-1');
        $delegation->setOriginalOwnerId('alice');
        $delegation->setDelegatedTo('bob');
        $this->m['delegation']->method('findByOriginalOwner')->willReturn([$delegation]);

        $this->m['secret']->expects($this->once())
            ->method('reassignOwner')
            ->with('sec-1', 'bob');
        $this->m['delegation']->expects($this->once())
            ->method('makePermanentByOriginalOwner')
            ->with('alice');

        $this->m['share']->method('findBySourceSecret')->willReturn([]);
        $this->m['group']->method('findBySecret')->willReturn([]);
        $this->m['share']->method('findByTargetUser')->willReturn([]);
        $this->m['link']->method('findByCreatedBy')->willReturn([]);
        $this->m['suite']->method('findByOwner')->willReturn([]);
        $this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['request']->method('deleteByCreatedBy')->willReturn(0);
        $this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

        $report = $service->deleteAllFor('alice');
        $this->assertSame(1, $report->secretsTransferred);
    }//end testDelegatedSecretTransfersOwnership()

    /**
     * Step 2: a granted share detaches — link deleted, recipient copy tombstoned
     * with a NON-PERSONAL reason (no deleted-user identity written).
     *
     * @return void
     */
    public function testGrantedShareDetachedWithNonPersonalTombstone(): void
    {
        $service = $this->build();
        $secret  = $this->secret('sec-1');
        $this->m['secret']->method('findByOwner')->willReturn([$secret]);
        $this->m['delegation']->method('findByOriginalOwner')->willReturn([]);

        $share = new ShareTarget();
        $share->setSourceSecretId('sec-1');
        $share->setTargetUserId('bob');
        $share->setSecretId('recipient-copy-1');
        $this->m['share']->method('findBySourceSecret')->willReturn([$share]);
        $this->m['group']->method('findBySecret')->willReturn([]);

        // Tombstone the recipient copy with the non-personal reason.
        $this->m['secret']->expects($this->once())
            ->method('tombstone')
            ->with('recipient-copy-1', AccountDeletionService::TOMBSTONE_REASON);
        // Sever the link.
        $this->m['share']->expects($this->once())
            ->method('deleteBySourceSecret')
            ->with('sec-1');

        $this->m['share']->method('findByTargetUser')->willReturn([]);
        $this->m['link']->method('findByCreatedBy')->willReturn([]);
        $this->m['suite']->method('findByOwner')->willReturn([]);
        $this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['request']->method('deleteByCreatedBy')->willReturn(0);
        $this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

        $report = $service->deleteAllFor('alice');
        $this->assertSame(1, $report->sharesDetached);
        // The non-personal reason must not embed the deleted user's id.
        $this->assertStringNotContainsString('alice', AccountDeletionService::TOMBSTONE_REASON);
    }//end testGrantedShareDetachedWithNonPersonalTombstone()

    /**
     * Step 3: received shares are removed (link severed) and the original owner's
     * secret is untouched (the service never deletes by source secret here).
     *
     * @return void
     */
    public function testReceivedSharesRemoved(): void
    {
        $service = $this->build();
        $this->m['secret']->method('findByOwner')->willReturn([]);
        $this->m['delegation']->method('findByOriginalOwner')->willReturn([]);

        $received = new ShareTarget();
        $received->setSourceSecretId('owner-secret');
        $received->setTargetUserId('alice');
        $received->setSecretId('alice-copy');
        $this->m['share']->method('findByTargetUser')->willReturn([$received]);

        $this->m['share']->expects($this->once())
            ->method('deleteByTargetUser')
            ->with('alice');

        $this->m['link']->method('findByCreatedBy')->willReturn([]);
        $this->m['suite']->method('findByOwner')->willReturn([]);
        $this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['request']->method('deleteByCreatedBy')->willReturn(0);
        $this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
        $this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

        $report = $service->deleteAllFor('alice');
        $this->assertSame(1, $report->sharesRemoved);
    }//end testReceivedSharesRemoved()

    /**
     * Steps 4-7: link shares, requests, secrets, folders, suites + migrations,
     * settings all removed, with report counts.
     *
     * @return void
     */
    public function testFullCascadeCountsAndSuiteRemoval(): void
    {
        $service = $this->build();
        $this->m['secret']->method('findByOwner')->willReturn([]);
        $this->m['delegation']->method('findByOriginalOwner')->willReturn([]);
        $this->m['share']->method('findByTargetUser')->willReturn([]);
        $this->m['link']->method('findByCreatedBy')->willReturn(['a', 'b']);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $this->m['suite']->method('findByOwner')->willReturn([$suite]);

        $this->m['link']->expects($this->once())->method('deleteByUserId')->with('alice');
        $this->m['request']->method('deleteByCreatedBy')->willReturn(4);
        $this->m['secret']->method('deleteByOwnerUser')->willReturn(200);
        $this->m['folder']->method('deleteByOwnerUser')->willReturn(7);
        $this->m['migration']->expects($this->once())->method('deleteBySuiteIds')->with(['suite-1'])->willReturn(1);
        $this->m['suite']->method('deleteByOwnerUser')->willReturn(1);
        $this->m['setting']->expects($this->once())->method('deleteByUser')->with('alice');

        $report = $service->deleteAllFor('alice');
        $this->assertSame(2, $report->linkSharesDeleted);
        $this->assertSame(4, $report->requestsDeleted);
        $this->assertSame(200, $report->secretsDeleted);
        $this->assertSame(7, $report->foldersDeleted);
        $this->assertSame(1, $report->suitesDeleted);
        $this->assertTrue($report->settingsDeleted);
    }//end testFullCascadeCountsAndSuiteRemoval()

    /**
     * AccountDataDeletedEvent is dispatched once, on a completed run, carrying
     * counts + trigger only.
     *
     * @return void
     */
    public function testEventDispatchedOnCompletionWithCountsOnly(): void
    {
        $service = $this->build();
        $this->emptyBaseline();

        $captured = null;
        $this->m['dispatcher']->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(
                    function ($event) use (&$captured) {
                        $captured = $event;
                    }
                    );

        $service->deleteAllFor('alice', 'in-app');

        $this->assertInstanceOf(AccountDataDeletedEvent::class, $captured);
        $this->assertSame('alice', $captured->getUserId());
        $this->assertSame('in-app', $captured->getTrigger());
        $meta = $captured->getMetadata();
        $this->assertSame(['trigger', 'secretCount', 'shareCount', 'requestCount', 'suiteCount'], array_keys($meta));
    }//end testEventDispatchedOnCompletionWithCountsOnly()

    /**
     * Idempotency: running the cascade twice completes without error.
     *
     * @return void
     */
    public function testIdempotentReRun(): void
    {
        $service = $this->build();
        $this->emptyBaseline();
        $this->m['dispatcher']->method('dispatchTyped');

        $first  = $service->deleteAllFor('alice');
        $second = $service->deleteAllFor('alice');

        $this->assertSame(0, $first->secretsDeleted);
        $this->assertSame(0, $second->secretsDeleted);
    }//end testIdempotentReRun()
}//end class
