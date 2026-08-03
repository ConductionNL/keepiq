<?php

/**
 * Unit tests for ShareService.
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ShareService — covers the hardened §3.2-3.6 surface
 * (owner/delegate authorization, recipient-suite precondition,
 * one-share-per-pair invariant, batch creation, sync optimistic locking,
 * compromise-flag clearance, owner-view scoping for listShares).
 */
class ShareServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var ShareService
     */
    private ShareService $service;

    /**
     * Mock share-target mapper.
     *
     * @var ShareTargetMapper&MockObject
     */
    private ShareTargetMapper $mapper;

    /**
     * Mock Secret mapper.
     *
     * @var SecretMapper&MockObject
     */
    private SecretMapper $secretMapper;

    /**
     * Mock EncryptionSuite mapper.
     *
     * @var EncryptionSuiteMapper&MockObject
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * Mock SecretDelegation mapper.
     *
     * @var SecretDelegationMapper&MockObject
     */
    private SecretDelegationMapper $delegationMapper;

    /**
     * Mock NotificationService.
     *
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

    /**
     * Mock DB connection.
     *
     * @var IDBConnection&MockObject
     */
    private IDBConnection $db;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper           = $this->createMock(originalClassName: ShareTargetMapper::class);
        $this->secretMapper     = $this->createMock(originalClassName: SecretMapper::class);
        $this->suiteMapper      = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->delegationMapper = $this->createMock(originalClassName: SecretDelegationMapper::class);
        $this->notificationService = $this->createMock(originalClassName: NotificationService::class);
        $this->db = $this->createMock(originalClassName: IDBConnection::class);
        $logger   = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new ShareService(
            mapper: $this->mapper,
            secretMapper: $this->secretMapper,
            suiteMapper: $this->suiteMapper,
            delegationMapper: $this->delegationMapper,
            notificationService: $this->notificationService,
            db: $this->db,
            logger: $logger
        );
    }//end setUp()

    /**
     * Helper: build a Secret with user owner.
     *
     * @param string $id      The secret ID
     * @param string $ownerId The owner Nextcloud user ID
     *
     * @return Secret
     */
    private function makeOwnerSecret(string $id, string $ownerId): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setOwnerType('user');
        $secret->setOwnerId($ownerId);
        $secret->setName('demo-secret');
        return $secret;
    }//end makeOwnerSecret()

    /**
     * Helper: stub the recipient as having an active EncryptionSuite.
     *
     * @return void
     */
    private function stubRecipientHasSuite(): void
    {
        $this->suiteMapper->method('findActiveByOwner')
            ->willReturn(new EncryptionSuite());
    }//end stubRecipientHasSuite()

    /**
     * Helper: stub recipient as having NO active suite.
     *
     * @return void
     */
    private function stubRecipientNoSuite(): void
    {
        $this->suiteMapper->method('findActiveByOwner')
            ->willThrowException(new DoesNotExistException('no suite'));
    }//end stubRecipientNoSuite()

    /**
     * Test createShare persists a row when the owner shares with a
     * recipient that has an active EncryptionSuite, and notifies the
     * recipient.
     *
     * @return void
     */
    public function testCreateShareInsertsRowWhenOwnerWithSuite(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->with('src-1')->willReturn($source);
        $this->stubRecipientHasSuite();
        $this->mapper->method('findBySourceSecretAndTargetUser')
            ->willThrowException(new DoesNotExistException('no row'));

        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (ShareTarget $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $this->notificationService->expects($this->once())
            ->method('notify')
            ->with('secret_shared', 'bob');

        $result = $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );

        $this->assertSame($captured, $result);
        $this->assertSame('src-1', $result->getSourceSecretId());
        $this->assertSame('bob', $result->getTargetUserId());
        $this->assertSame('alice', $result->getCreatedBy());
        $this->assertNotSame('', $result->getId(), 'UUID should be generated');
    }//end testCreateShareInsertsRowWhenOwnerWithSuite()

    /**
     * Test createShare allows an active delegate to share.
     *
     * @return void
     */
    public function testCreateShareAllowsActiveDelegate(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->with('src-1')->willReturn($source);
        $this->stubRecipientHasSuite();
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->with('src-1', 'carol')
            ->willReturn(new SecretDelegation());
        $this->mapper->method('findBySourceSecretAndTargetUser')
            ->willThrowException(new DoesNotExistException('no row'));

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnArgument(0);

        $result = $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'carol'
        );

        $this->assertSame('carol', $result->getCreatedBy());
    }//end testCreateShareAllowsActiveDelegate()

    /**
     * Test createShare rejects a non-owner non-delegate.
     *
     * @return void
     */
    public function testCreateShareRejectsNonOwnerNonDelegate(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('no delegation'));

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'mallory'
        );
    }//end testCreateShareRejectsNonOwnerNonDelegate()

    /**
     * Test createShare rejects when the recipient has no active suite.
     *
     * @return void
     */
    public function testCreateShareRejectsRecipientWithoutSuite(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $this->stubRecipientNoSuite();

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no active encryption suite');

        $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );
    }//end testCreateShareRejectsRecipientWithoutSuite()

    /**
     * Test createShare rejects duplicate (source, recipient) pair.
     *
     * @return void
     */
    public function testCreateShareRejectsDuplicateRecipient(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $this->stubRecipientHasSuite();
        $this->mapper->method('findBySourceSecretAndTargetUser')
            ->willReturn(new ShareTarget());

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already shared');

        $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );
    }//end testCreateShareRejectsDuplicateRecipient()

    /**
     * Test createShare rejects sharing with self.
     *
     * @return void
     */
    public function testCreateShareRejectsSelfShare(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot share a secret with its owner');

        $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'alice',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );
    }//end testCreateShareRejectsSelfShare()

    /**
     * Test createShare rejects empty source secret.
     *
     * @return void
     */
    public function testCreateShareRejectsEmptySource(): void
    {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sourceSecretId is required');

        $this->service->createShare(
            sourceSecretId: '',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );
    }//end testCreateShareRejectsEmptySource()

    /**
     * Test listSharesForSecret without userId returns raw mapper result.
     *
     * @return void
     */
    public function testListSharesForSecretBackCompatNoUser(): void
    {
        $row = new ShareTarget();
        $row->setId('st-1');
        $this->mapper->expects($this->once())
            ->method('findBySourceSecret')
            ->with('src-1')
            ->willReturn([$row]);

        $result = $this->service->listSharesForSecret('src-1');

        $this->assertCount(1, $result);
    }//end testListSharesForSecretBackCompatNoUser()

    /**
     * Test listSharesForSecret returns rows when caller is owner.
     *
     * @return void
     */
    public function testListSharesForSecretOwnerSeesRows(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $row = new ShareTarget();
        $row->setId('st-1');
        $this->mapper->expects($this->once())
            ->method('findBySourceSecret')
            ->willReturn([$row]);

        $result = $this->service->listSharesForSecret('src-1', 'alice');

        $this->assertCount(1, $result);
    }//end testListSharesForSecretOwnerSeesRows()

    /**
     * Test listSharesForSecret returns empty array when caller is recipient.
     *
     * @return void
     */
    public function testListSharesForSecretRecipientSeesEmpty(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('no'));

        $this->mapper->expects($this->never())->method('findBySourceSecret');

        $result = $this->service->listSharesForSecret('src-1', 'bob');

        $this->assertSame([], $result);
    }//end testListSharesForSecretRecipientSeesEmpty()

    /**
     * Test revokeShare deletes both the recipient copy and the share row when
     * the caller is the owner.
     *
     * @return void
     */
    public function testRevokeShareDeletesWhenAuthorized(): void
    {
        $entity = new ShareTarget();
        $entity->setId('st-1');
        $entity->setSourceSecretId('src-1');
        $entity->setSecretId('copy-1');

        $source = $this->makeOwnerSecret('src-1', 'alice');
        $copy   = new Secret();
        $copy->setId('copy-1');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('st-1')
            ->willReturn($entity);

        $this->secretMapper->method('findById')->willReturnMap(
                [
                    ['src-1', $source],
                    ['copy-1', $copy],
                ]
                );

        $this->secretMapper->expects($this->once())->method('delete')->with($copy);
        $this->mapper->expects($this->once())->method('delete')->with($entity);

        $this->service->revokeShare(shareId: 'st-1', userId: 'alice');
    }//end testRevokeShareDeletesWhenAuthorized()

    /**
     * Test revokeShare rejects unauthorized callers.
     *
     * @return void
     */
    public function testRevokeShareRejectsNonOwnerNonDelegate(): void
    {
        $entity = new ShareTarget();
        $entity->setId('st-1');
        $entity->setSourceSecretId('src-1');
        $entity->setSecretId('copy-1');

        $source = $this->makeOwnerSecret('src-1', 'alice');

        $this->mapper->method('findById')->willReturn($entity);
        $this->secretMapper->method('findById')->willReturnMap(
                [
                    ['src-1', $source],
                ]
                );
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('no'));

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->revokeShare(shareId: 'st-1', userId: 'mallory');
    }//end testRevokeShareRejectsNonOwnerNonDelegate()

    /**
     * Test revokeShare 404 when the row is missing.
     *
     * @return void
     */
    public function testRevokeShareThrowsWhenNotFound(): void
    {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Share not found');

        $this->service->revokeShare(shareId: 'missing', userId: 'alice');
    }//end testRevokeShareThrowsWhenNotFound()

    /**
     * Test syncUpdate writes every recipient copy and clears the compromise
     * flag.
     *
     * @return void
     */
    public function testSyncUpdateWritesEveryCopyAndClearsCompromise(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $source->setUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));

        $copy1 = new Secret();
        $copy1->setId('copy-1');
        $copy1->setPossiblyCompromisedAt(new DateTime('2026-01-01'));
        $copy2 = new Secret();
        $copy2->setId('copy-2');

        $this->secretMapper->method('findById')->willReturnMap(
                [
                    ['src-1',  $source],
                    ['copy-1', $copy1],
                    ['copy-2', $copy2],
                ]
                );

        // The membership guard (folder-permission-grades §2.3) only
        // writes ACTUAL recipient copies of the source.
        $row1 = new ShareTarget();
        $row1->setSourceSecretId('src-1');
        $row1->setSecretId('copy-1');
        $row2 = new ShareTarget();
        $row2->setSourceSecretId('src-1');
        $row2->setSecretId('copy-2');
        $this->mapper->method('findBySourceSecret')->willReturn([$row1, $row2]);

        $this->secretMapper->expects($this->exactly(2))->method('update');

        $written = $this->service->syncUpdate(
            secretId: 'src-1',
            updates: [
                ['secretId' => 'copy-1', 'key' => 'enc-1', 'login' => 'l1', 'additionalFields' => null],
                ['secretId' => 'copy-2', 'key' => 'enc-2'],
            ],
            expectedUpdatedAt: '2026-01-01T00:00:00+00:00',
            userId: 'alice'
        );

        $this->assertSame(2, $written);
        $this->assertNull($copy1->getPossiblyCompromisedAt());
    }//end testSyncUpdateWritesEveryCopyAndClearsCompromise()

    /**
     * Test syncUpdate optimistic-lock failure.
     *
     * @return void
     */
    public function testSyncUpdateRejectsStaleExpectedTimestamp(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $source->setUpdatedAt(new DateTime('2026-01-02T00:00:00+00:00'));
        $this->secretMapper->method('findById')->willReturn($source);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has changed');

        $this->service->syncUpdate(
            secretId: 'src-1',
            updates: [],
            expectedUpdatedAt: '2026-01-01T00:00:00+00:00',
            userId: 'alice'
        );
    }//end testSyncUpdateRejectsStaleExpectedTimestamp()

    /**
     * Test createBatchShares creates each row inside one transaction.
     *
     * @return void
     */
    public function testCreateBatchSharesAllRecipients(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $this->stubRecipientHasSuite();
        $this->mapper->method('findBySourceSecretAndTargetUser')
            ->willThrowException(new DoesNotExistException('no row'));

        $this->mapper->expects($this->exactly(2))->method('insert')
            ->willReturnArgument(0);

        $created = $this->service->createBatchShares(
            sourceSecretId: 'src-1',
            shares: [
                ['targetUserId' => 'bob',   'recipientSecretId' => 'copy-bob'],
                ['targetUserId' => 'carol', 'recipientSecretId' => 'copy-carol'],
            ],
            groupShareId: 'gs-1',
            userId: 'alice'
        );

        $this->assertCount(2, $created);
        $this->assertSame('gs-1', $created[0]->getGroupShareId());
    }//end testCreateBatchSharesAllRecipients()

    /**
     * bulk-actions §8.3: registerDirectShares is idempotent (an existing
     * share reports `exists` and inserts nothing), per-item owner-scoped
     * (a foreign secret is `not_owned`, never a batch failure), skips a
     * suite-less recipient, and reports every row exactly once.
     *
     * @return void
     */
    public function testRegisterDirectSharesIdempotentOwnerScopedReport(): void
    {
        $mine = $this->makeOwnerSecret('sec-mine', 'alice');
        $bobs = $this->makeOwnerSecret('sec-bobs', 'bob');
        $this->secretMapper->method('findById')->willReturnCallback(
            static function (string $id) use ($mine, $bobs): Secret {
                return match ($id) {
                    'sec-mine' => $mine,
                    'sec-bobs' => $bobs,
                    default    => throw new DoesNotExistException('missing'),
                };
            }
        );
        $this->stubRecipientHasSuite();
        // No existing shares.
        $this->mapper->method('findBySourceSecretAndTargetUser')
            ->willThrowException(new DoesNotExistException('no row'));

        $inserted = [];
        $this->mapper->method('insert')->willReturnCallback(
            static function ($row) use (&$inserted) {
                $inserted[] = $row;
                return $row;
            }
        );

        $report = $this->service->registerDirectShares(
            userId: 'alice',
            shares: [
                [
                    'sourceSecretId' => 'sec-mine',
                    'targetUserId'   => 'carol',
                    'encryptedKey'   => 'BLOB',
                ],
                [
                    'sourceSecretId' => 'sec-bobs',
                    'targetUserId'   => 'carol',
                    'encryptedKey'   => 'BLOB',
                ],
                [
                    'sourceSecretId' => 'sec-gone',
                    'targetUserId'   => 'carol',
                    'encryptedKey'   => 'BLOB',
                ],
                [
                    'sourceSecretId' => 'sec-mine',
                    'targetUserId'   => 'alice',
                    'encryptedKey'   => 'BLOB',
                ],
            ]
        );

        $this->assertCount(4, $report);
        $this->assertSame('created', $report[0]['status']);
        $this->assertSame('not_owned', $report[1]['status']);
        $this->assertSame('not_owned', $report[2]['status']);
        $this->assertSame('self', $report[3]['status']);
        $this->assertCount(1, $inserted);
    }//end testRegisterDirectSharesIdempotentOwnerScopedReport()

    /**
     * bulk-actions §8.3: an already-shared pair is `exists` (idempotent
     * resume) and a recipient without a suite is `no_suite` — neither
     * inserts a row nor aborts the batch.
     *
     * @return void
     */
    public function testRegisterDirectSharesExistsAndNoSuite(): void
    {
        $mine = $this->makeOwnerSecret('sec-mine', 'alice');
        $this->secretMapper->method('findById')->willReturn($mine);
        $this->stubRecipientNoSuite();
        $this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
            static function (string $sourceSecretId, string $targetUserId) {
                if ($targetUserId === 'dave') {
                    return new ShareTarget();
                }

                throw new DoesNotExistException('no row');
            }
        );
        $this->mapper->expects($this->never())->method('insert');

        $report = $this->service->registerDirectShares(
            userId: 'alice',
            shares: [
                [
                    'sourceSecretId' => 'sec-mine',
                    'targetUserId'   => 'dave',
                    'encryptedKey'   => 'BLOB',
                ],
                [
                    'sourceSecretId' => 'sec-mine',
                    'targetUserId'   => 'suite-less',
                    'encryptedKey'   => 'BLOB',
                ],
            ]
        );

        $this->assertSame('exists', $report[0]['status']);
        $this->assertSame('no_suite', $report[1]['status']);
    }//end testRegisterDirectSharesExistsAndNoSuite()

    /**
     * Build a ShareService wired to a TeamFolderService mock that
     * resolves the given grade (folder-permission-grades §5.2).
     *
     * @param string|null $grade The resolved grade
     *
     * @return ShareService
     */
    private function gradedService(?string $grade): ShareService
    {
        $teamFolderService = $this->createMock(originalClassName: \OCA\Doriath\Service\TeamFolderService::class);
        $teamFolderService->method('resolveGrade')->willReturn($grade);

        return new ShareService(
            mapper: $this->mapper,
            secretMapper: $this->secretMapper,
            suiteMapper: $this->suiteMapper,
            delegationMapper: $this->delegationMapper,
            notificationService: $this->notificationService,
            db: $this->db,
            logger: $this->createMock(originalClassName: LoggerInterface::class),
            auditTrail: null,
            teamFolderService: $teamFolderService,
        );
    }//end gradedService()

    /**
     * folder-permission-grades §5.2: a write-grade non-owner syncs, and
     * the membership guard confines writes to the source + its ACTUAL
     * recipient copies — a foreign id in the batch is skipped.
     *
     * @return void
     */
    public function testSyncUpdateAcceptsWriteGradeAndGuardsCopies(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $copy   = $this->makeOwnerSecret('copy-1', 'carol');
        $victim = $this->makeOwnerSecret('victim-1', 'mallory-target');
        $this->secretMapper->method('findById')->willReturnCallback(
            static fn (string $id) => match ($id) {
                'src-1'    => $source,
                'copy-1'   => $copy,
                'victim-1' => $victim,
                default    => throw new DoesNotExistException('missing'),
            }
        );
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('none'));

        $shareRow = new ShareTarget();
        $shareRow->setSourceSecretId('src-1');
        $shareRow->setTargetUserId('carol');
        $shareRow->setSecretId('copy-1');
        $this->mapper->method('findBySourceSecret')->willReturn([$shareRow]);

        $written = [];
        $this->secretMapper->method('update')->willReturnCallback(
            static function (Secret $row) use (&$written) {
                $written[] = $row->getId();
                return $row;
            }
        );

        $service = $this->gradedService(grade: 'write');
        $updated = $service->syncUpdate(
            secretId: 'src-1',
            updates: [
                ['secretId' => 'copy-1', 'key' => 'NEWBLOB_CAROL'],
                ['secretId' => 'src-1', 'key' => 'NEWBLOB_OWNER'],
                ['secretId' => 'victim-1', 'key' => 'EVIL'],
            ],
            expectedUpdatedAt: '',
            userId: 'bob',
        );

        $this->assertSame(2, $updated);
        $this->assertEqualsCanonicalizing(['copy-1', 'src-1'], $written);
        $this->assertStringNotContainsString('victim-1', implode(',', $written));
    }//end testSyncUpdateAcceptsWriteGradeAndGuardsCopies()

    /**
     * folder-permission-grades §5.2: a read-grade member and an
     * ungraded caller are rejected with the existing exception.
     *
     * @return void
     */
    public function testSyncUpdateRejectsReadAndUngraded(): void
    {
        $source = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($source);
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('none'));

        foreach (['read', null] as $grade) {
            $service = $this->gradedService(grade: $grade);
            try {
                $service->syncUpdate(secretId: 'src-1', updates: [], expectedUpdatedAt: '', userId: 'bob');
                $this->fail('Grade "'.var_export($grade, true).'" must be rejected');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Not authorized', $exception->getMessage());
            }
        }
    }//end testSyncUpdateRejectsReadAndUngraded()
}//end class
