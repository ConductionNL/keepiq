<?php

/**
 * Unit tests for ShareService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderService;
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
class ShareServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ShareService
	 */
	private ShareService $service;

	/**
	 * @var GroupShareMapper&MockObject
	 */
	private GroupShareMapper $groupShareMapper;

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
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->delegationMapper = $this->createMock(originalClassName: SecretDelegationMapper::class);
		$this->notificationService = $this->createMock(originalClassName: NotificationService::class);
		$this->db = $this->createMock(originalClassName: IDBConnection::class);
		$this->groupShareMapper = $this->createMock(originalClassName: GroupShareMapper::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = $this->wireService(logger: $logger);
	}//end setUp()

	/**
	 * Assemble a ShareService over the shared mocks with its extracted
	 * collaborators wired the way the Nextcloud container wires them: one
	 * ShareAuthorizationService and one RecipientSecretCopyFactory, shared
	 * by the registrar, the sync service and the revocation service.
	 *
	 * @param LoggerInterface|null $logger The logger
	 * @param TeamFolderService|null $teamFolderService The team-folder service (grade resolution)
	 * @param ShareAuditTrail|null $auditTrail The sync's audit trail
	 *
	 * @return ShareService
	 */
	private function wireService(
		?LoggerInterface $logger = null,
		?TeamFolderService $teamFolderService = null,
		?ShareAuditTrail $auditTrail = null,
	): ShareService {
		$logger ??= $this->createMock(originalClassName: LoggerInterface::class);

		$auth = new ShareAuthorizationService(
			secretMapper: $this->secretMapper,
			delegationMapper: $this->delegationMapper,
			suiteMapper: $this->suiteMapper,
			teamFolderService: $teamFolderService,
		);

		$copyFactory = new RecipientSecretCopyFactory(
			secretMapper: $this->secretMapper,
			suiteMapper: $this->suiteMapper,
		);

		return new ShareService(
			mapper: $this->mapper,
			db: $this->db,
			notificationService: $this->notificationService,
			auth: $auth,
			directRegistrar: new DirectShareRegistrar(
				mapper: $this->mapper,
				secretMapper: $this->secretMapper,
				copyFactory: $copyFactory,
				notificationService: $this->notificationService,
				groupShareMapper: $this->groupShareMapper,
			),
			syncService: new ShareSyncService(
				mapper: $this->mapper,
				secretMapper: $this->secretMapper,
				suiteMapper: $this->suiteMapper,
				db: $this->db,
				auth: $auth,
				auditTrail: $auditTrail,
			),
			revocationService: new ShareRevocationService(
				mapper: $this->mapper,
				secretMapper: $this->secretMapper,
				db: $this->db,
				logger: $logger,
				auth: $auth,
			),
		);
	}//end wireService()

	/**
	 * Helper: build a Secret with user owner.
	 *
	 * @param string $id The secret ID
	 * @param string $ownerId The owner Nextcloud user ID
	 *
	 * @return Secret
	 */
	private function makeOwnerSecret(string $id, string $ownerId): Secret {
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
	private function stubRecipientHasSuite(): void {
		$this->suiteMapper->method('findActiveByOwner')
			->willReturn(new EncryptionSuite());
	}//end stubRecipientHasSuite()

	/**
	 * Helper: stub recipient as having NO active suite.
	 *
	 * @return void
	 */
	private function stubRecipientNoSuite(): void {
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
	public function testCreateShareInsertsRowWhenOwnerWithSuite(): void {
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
	public function testCreateShareAllowsActiveDelegate(): void {
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
	public function testCreateShareRejectsNonOwnerNonDelegate(): void {
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
	public function testCreateShareRejectsRecipientWithoutSuite(): void {
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
	public function testCreateShareRejectsDuplicateRecipient(): void {
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
	public function testCreateShareRejectsSelfShare(): void {
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
	public function testCreateShareRejectsEmptySource(): void {
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
	public function testListSharesForSecretBackCompatNoUser(): void {
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
	public function testListSharesForSecretOwnerSeesRows(): void {
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
	public function testListSharesForSecretRecipientSeesEmpty(): void {
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
	public function testRevokeShareDeletesWhenAuthorized(): void {
		$entity = new ShareTarget();
		$entity->setId('st-1');
		$entity->setSourceSecretId('src-1');
		$entity->setSecretId('copy-1');

		$source = $this->makeOwnerSecret('src-1', 'alice');
		$copy = new Secret();
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
	 * Keepiq#83: ending every share of a secret (trash or delete) removes
	 * each recipient's copy too, not only the link rows. A copy already gone
	 * does not stop the others.
	 *
	 * @return void
	 */
	public function testDeleteAllForSecretRemovesEveryRecipientCopy(): void {
		$bob = new ShareTarget();
		$bob->setSourceSecretId('src-1');
		$bob->setSecretId('copy-bob');
		$carol = new ShareTarget();
		$carol->setSourceSecretId('src-1');
		$carol->setSecretId('copy-carol');
		$this->mapper->method('findBySourceSecret')->with('src-1')->willReturn([$bob, $carol]);

		$copyBob = new Secret();
		$copyBob->setId('copy-bob');
		$this->secretMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($copyBob): Secret {
				if ($id === 'copy-bob') {
					return $copyBob;
				}

				throw new DoesNotExistException('gone');
			}
		);

		$this->secretMapper->expects($this->once())->method('delete')->with($copyBob);
		$this->mapper->expects($this->once())->method('deleteBySourceSecret')->with('src-1');
		$this->db->expects($this->once())->method('commit');

		$this->service->deleteAllForSecret('src-1');
	}//end testDeleteAllForSecretRemovesEveryRecipientCopy()

	/**
	 * Test revokeShare rejects unauthorized callers.
	 *
	 * @return void
	 */
	public function testRevokeShareRejectsNonOwnerNonDelegate(): void {
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
	public function testRevokeShareThrowsWhenNotFound(): void {
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
	public function testSyncUpdateWritesEveryCopyAndClearsCompromise(): void {
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

		$this->secretMapper->expects($this->once())->method('advanceVersion')
			->with('src-1', $this->callback(
				static fn (DateTime $base): bool => $base->getTimestamp() === (new DateTime('2026-01-01T00:00:00+00:00'))->getTimestamp()
			))
			->willReturn(1);
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
	 * The old sync route keeps its own transaction around the copy writes.
	 *
	 * @return void
	 */
	public function testSyncUpdateCommitsItsCopiesInOneTransaction(): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$copy = new Secret();
		$copy->setId('copy-1');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$this->mapper->method('findBySourceSecret')->willReturn([$row]);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-1']],
			expectedUpdatedAt: '',
			userId: 'alice'
		);
	}//end testSyncUpdateCommitsItsCopiesInOneTransaction()

	/**
	 * The copy writes a share-save runs inside its own transaction open none,
	 * stamp every row with the save's version and rotate the source's key age.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testWriteCopyBlobsOpensNoTransactionAndStampsTheSaveVersion(): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$copy = new Secret();
		$copy->setId('copy-1');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$this->db->expects($this->never())->method('beginTransaction');
		$sync = new ShareSyncService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			suiteMapper: $this->suiteMapper,
			db: $this->db,
			auth: $this->createMock(ShareAuthorizationService::class),
		);
		$at = new DateTime('2026-10-13T12:00:00+00:00');

		$written = $sync->writeCopyBlobs(
			source: $source,
			updates: [
				['secretId' => 'src-1', 'key' => 'enc-src'],
				['secretId' => 'copy-1', 'key' => 'enc-1', 'login' => null],
				['secretId' => 'stranger', 'key' => 'enc-x'],
			],
			allowedIds: ['src-1' => true, 'copy-1' => true],
			savedAt: $at
		);

		$this->assertSame(2, $written);
		$this->assertSame($at->getTimestamp(), $copy->getUpdatedAt()?->getTimestamp());
		$this->assertNull($copy->getLogin());
		$this->assertSame($at->getTimestamp(), $source->getUpdatedAt()?->getTimestamp());
		$this->assertSame($at->getTimestamp(), $source->getKeyUpdatedAt()?->getTimestamp());
		$this->assertNull($copy->getKeyUpdatedAt());
	}//end testWriteCopyBlobsOpensNoTransactionAndStampsTheSaveVersion()

	/**
	 * A recipient's star and last-used time survive the owner's edit: the
	 * sync writes only the value columns of the copy
	 * (vault-favourites-tags-and-last-used, "A recipient's star survives the owner's edit").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
	 */
	public function testSyncUpdateLeavesTheRecipientsStar(): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$source->setUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));

		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setIsFavourite(true);
		$copy->setLastUsedAt(new DateTime('2026-09-30T08:00:00+00:00'));
		// As loaded from the database: nothing pending.
		$copy->resetUpdatedFields();

		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$this->mapper->method('findBySourceSecret')->willReturn([$row]);
		$this->secretMapper->method('advanceVersion')->willReturn(1);

		$written = [];
		$this->secretMapper->method('update')->willReturnCallback(
			static function (Secret $secret) use (&$written): Secret {
				$written = array_keys($secret->getUpdatedFields());
				return $secret;
			}
		);

		$this->service->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-new']],
			expectedUpdatedAt: '2026-01-01T00:00:00+00:00',
			userId: 'alice'
		);

		$this->assertContains('key', $written);
		$this->assertNotContains('isFavourite', $written);
		$this->assertNotContains('lastUsedAt', $written);
		$this->assertTrue($copy->getIsFavourite());
	}//end testSyncUpdateLeavesTheRecipientsStar()

	/**
	 * Test syncUpdate optimistic-lock failure.
	 *
	 * @return void
	 */
	public function testSyncUpdateRejectsStaleExpectedTimestamp(): void {
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
	 * The sync that names the source version it was prepared on and the one
	 * that doesn't.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function expectedVersionOrNone(): array {
		return [
			'with an expected version'    => ['2026-01-01T00:00:00+00:00'],
			'without an expected version' => [''],
		];
	}//end expectedVersionOrNone()

	/**
	 * A save that commits after the sync's read but before its write moves
	 * the source: the compare-and-set finds it gone, and the sync is refused
	 * and rolled back instead of overwriting the save's copies, whether or
	 * not the client named the version.
	 *
	 * @param string $expectedUpdatedAt The client's expected source version, or ''
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('expectedVersionOrNone')]
	public function testSyncUpdateOverASourceThatMovesAfterItsReadIsRefused(string $expectedUpdatedAt): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$source->setUpdatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$copy = new Secret();
		$copy->setId('copy-1');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$this->mapper->method('findBySourceSecret')->willReturn([$row]);

		$this->secretMapper->expects($this->once())->method('advanceVersion')->willReturn(0);
		$this->secretMapper->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('has changed');

		$this->service->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-1']],
			expectedUpdatedAt: $expectedUpdatedAt,
			userId: 'alice'
		);
	}//end testSyncUpdateOverASourceThatMovesAfterItsReadIsRefused()

	/**
	 * A sync moves the source and stamps its copies past the stored version,
	 * even when that version sits ahead of now, so a save in the same second
	 * still gets a new version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testSyncUpdateMovesTheSourcePastItsStoredVersion(): void {
		$stored = (new DateTime('+1 hour'))->getTimestamp();
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$source->setUpdatedAt(new DateTime('@'.$stored));
		$copy = new Secret();
		$copy->setId('copy-1');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$this->mapper->method('findBySourceSecret')->willReturn([$row]);

		$this->secretMapper->expects($this->once())->method('advanceVersion')
			->with(
				'src-1',
				$this->callback(static fn (DateTime $base): bool => $base->getTimestamp() === $stored),
				$this->callback(static fn (DateTime $savedAt): bool => $savedAt->getTimestamp() === $stored + 1)
			)
			->willReturn(1);

		$this->service->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-1']],
			expectedUpdatedAt: $source->getUpdatedAt()->format(DateTime::ATOM),
			userId: 'alice'
		);

		$this->assertSame($stored + 1, $copy->getUpdatedAt()?->getTimestamp());
	}//end testSyncUpdateMovesTheSourcePastItsStoredVersion()

	/**
	 * Test createBatchShares creates each row inside one transaction.
	 *
	 * @return void
	 */
	public function testCreateBatchSharesAllRecipients(): void {
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
	public function testRegisterDirectSharesIdempotentOwnerScopedReport(): void {
		$mine = $this->makeOwnerSecret('sec-mine', 'alice');
		$bobs = $this->makeOwnerSecret('sec-bobs', 'bob');
		$this->secretMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($mine, $bobs): Secret {
				return match ($id) {
					'sec-mine' => $mine,
					'sec-bobs' => $bobs,
					default => throw new DoesNotExistException('missing'),
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
					'targetUserId' => 'carol',
					'encryptedKey' => 'BLOB',
				],
				[
					'sourceSecretId' => 'sec-bobs',
					'targetUserId' => 'carol',
					'encryptedKey' => 'BLOB',
				],
				[
					'sourceSecretId' => 'sec-gone',
					'targetUserId' => 'carol',
					'encryptedKey' => 'BLOB',
				],
				[
					'sourceSecretId' => 'sec-mine',
					'targetUserId' => 'alice',
					'encryptedKey' => 'BLOB',
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
	 * sharing-02: a row carrying a groupShareId links the new ShareTarget
	 * to that group share, so revoking the group share revokes the copy.
	 * A group share of a DIFFERENT secret is refused as `invalid`, so a
	 * caller cannot hang a copy on someone else's group share.
	 *
	 * @return void
	 */
	public function testRegisterDirectSharesLinksAGroupShareOfTheSameSecret(): void {
		$mine = $this->makeOwnerSecret('sec-mine', 'alice');
		$this->secretMapper->method('findById')->willReturn($mine);
		$this->stubRecipientHasSuite();
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException('no row'));

		$ours = new GroupShare();
		$ours->setId('gs-1');
		$ours->setSecretId('sec-mine');
		$theirs = new GroupShare();
		$theirs->setId('gs-other');
		$theirs->setSecretId('sec-bobs');
		$this->groupShareMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($ours, $theirs): GroupShare {
				return match ($id) {
					'gs-1' => $ours,
					'gs-other' => $theirs,
					default => throw new DoesNotExistException('missing'),
				};
			}
		);

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
				['sourceSecretId' => 'sec-mine', 'targetUserId' => 'bob', 'encryptedKey' => 'BLOB', 'groupShareId' => 'gs-1'],
				['sourceSecretId' => 'sec-mine', 'targetUserId' => 'carol', 'encryptedKey' => 'BLOB', 'groupShareId' => 'gs-other'],
				['sourceSecretId' => 'sec-mine', 'targetUserId' => 'dave', 'encryptedKey' => 'BLOB', 'groupShareId' => 'gs-ghost'],
			]
		);

		$this->assertSame('created', $report[0]['status']);
		$this->assertSame('invalid', $report[1]['status']);
		$this->assertSame('invalid', $report[2]['status']);
		$this->assertCount(1, $inserted);
		$this->assertSame('gs-1', $inserted[0]->getGroupShareId());
	}//end testRegisterDirectSharesLinksAGroupShareOfTheSameSecret()

	/**
	 * bulk-actions §8.3: an already-shared pair is `exists` (idempotent
	 * resume) and a recipient without a suite is `no_suite` — neither
	 * inserts a row nor aborts the batch.
	 *
	 * @return void
	 */
	public function testRegisterDirectSharesExistsAndNoSuite(): void {
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
					'targetUserId' => 'dave',
					'encryptedKey' => 'BLOB',
				],
				[
					'sourceSecretId' => 'sec-mine',
					'targetUserId' => 'suite-less',
					'encryptedKey' => 'BLOB',
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
	 * @param ShareAuditTrail|null $auditTrail The sync's audit trail
	 *
	 * @return ShareService
	 */
	private function gradedService(?string $grade, ?ShareAuditTrail $auditTrail = null): ShareService {
		$teamFolderService = $this->createMock(originalClassName: TeamFolderService::class);
		$teamFolderService->method('resolveGrade')->willReturn($grade);

		return $this->wireService(teamFolderService: $teamFolderService, auditTrail: $auditTrail);
	}//end gradedService()

	/**
	 * Stub a source owned by alice with one copy, a sync of it, and a
	 * transaction that logs where it opens and commits.
	 *
	 * @param list<string> $log The log the transaction writes to
	 *
	 * @return void
	 */
	private function stubLoggedSync(array &$log): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$copy = new Secret();
		$copy->setId('copy-1');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$this->mapper->method('findBySourceSecret')->willReturn([$row]);
		$this->db->method('beginTransaction')->willReturnCallback(
			static function () use (&$log): void {
				$log[] = 'begin';
			}
		);
		$this->db->method('commit')->willReturnCallback(
			static function () use (&$log): void {
				$log[] = 'commit';
			}
		);
	}//end stubLoggedSync()

	/**
	 * A delegate's sync writes the owner's copies, so it is attributed to the
	 * delegate on the source, in the sync's transaction, as their share-save is.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function testADelegatesSyncIsAuditedOnTheSourceInItsTransaction(): void {
		$log = [];
		$this->stubLoggedSync(log: $log);
		$this->delegationMapper->method('findActiveBySecretAndUser')
			->with('src-1', 'carol')
			->willReturn(new SecretDelegation());
		$audit = $this->createMock(originalClassName: ShareAuditTrail::class);
		$audit->expects($this->once())->method('recordTeamWriteSync')
			->with('carol', 'src-1', 'demo-secret', 'delegate sync')
			->willReturnCallback(
				static function () use (&$log): void {
					$log[] = 'audit';
				}
			);

		$this->gradedService(grade: null, auditTrail: $audit)->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-1']],
			expectedUpdatedAt: '',
			userId: 'carol'
		);

		$this->assertSame(['begin', 'audit', 'commit'], $log);
	}//end testADelegatesSyncIsAuditedOnTheSourceInItsTransaction()

	/**
	 * The owner's sync has no attribution entry: the row's own audit covers it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function testTheOwnersSyncIsNotAttributed(): void {
		$log = [];
		$this->stubLoggedSync(log: $log);
		$audit = $this->createMock(originalClassName: ShareAuditTrail::class);
		$audit->expects($this->never())->method('recordTeamWriteSync');

		$written = $this->gradedService(grade: null, auditTrail: $audit)->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-1']],
			expectedUpdatedAt: '',
			userId: 'alice'
		);

		$this->assertSame(1, $written);
	}//end testTheOwnersSyncIsNotAttributed()

	/**
	 * A write-grade member's sync is attributed to them as a team write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function testATeamWritersSyncIsAuditedAsATeamWrite(): void {
		$log = [];
		$this->stubLoggedSync(log: $log);
		$this->delegationMapper->method('findActiveBySecretAndUser')
			->willThrowException(new DoesNotExistException('none'));
		$audit = $this->createMock(originalClassName: ShareAuditTrail::class);
		$audit->expects($this->once())->method('recordTeamWriteSync')
			->with('bob', 'src-1', 'demo-secret', 'team-write sync');

		$this->gradedService(grade: 'write', auditTrail: $audit)->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'copy-1', 'key' => 'enc-1']],
			expectedUpdatedAt: '',
			userId: 'bob'
		);
	}//end testATeamWritersSyncIsAuditedAsATeamWrite()

	/**
	 * folder-permission-grades §5.2: a write-grade non-owner syncs, and
	 * the membership guard confines writes to the source + its ACTUAL
	 * recipient copies — a foreign id in the batch is skipped.
	 *
	 * @return void
	 */
	public function testSyncUpdateAcceptsWriteGradeAndGuardsCopies(): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$copy = $this->makeOwnerSecret('copy-1', 'carol');
		$victim = $this->makeOwnerSecret('victim-1', 'mallory-target');
		$this->secretMapper->method('findById')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'src-1' => $source,
				'copy-1' => $copy,
				'victim-1' => $victim,
				default => throw new DoesNotExistException('missing'),
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
	public function testSyncUpdateRejectsReadAndUngraded(): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$this->secretMapper->method('findById')->willReturn($source);
		$this->delegationMapper->method('findActiveBySecretAndUser')
			->willThrowException(new DoesNotExistException('none'));

		foreach (['read', null] as $grade) {
			$service = $this->gradedService(grade: $grade);
			try {
				$service->syncUpdate(secretId: 'src-1', updates: [], expectedUpdatedAt: '', userId: 'bob');
				$this->fail('Grade "' . var_export($grade, true) . '" must be rejected');
			} catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('Not authorized', $exception->getMessage());
			}
		}
	}//end testSyncUpdateRejectsReadAndUngraded()
}//end class
