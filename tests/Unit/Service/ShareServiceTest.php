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
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareGrantReader;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
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
		// No secret is a received copy unless a test says so.
		$this->mapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('Not a copy'));
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
	 * @param SecretVersionService|null $versionService The sync's version history
	 *
	 * @return ShareService
	 */
	private function wireService(
		?LoggerInterface $logger = null,
		?TeamFolderService $teamFolderService = null,
		?ShareAuditTrail $auditTrail = null,
		?SecretVersionService $versionService = null,
	): ShareService {
		$logger ??= $this->createMock(originalClassName: LoggerInterface::class);

		$auth = new ShareAuthorizationService(
			secretMapper: $this->secretMapper,
			delegationMapper: $this->delegationMapper,
			teamFolderService: $teamFolderService,
		);

		$copyFactory = new RecipientSecretCopyFactory(
			secretMapper: $this->secretMapper,
			suiteMapper: $this->suiteMapper,
		);

		return new ShareService(
			mapper: $this->mapper,
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
				db: $this->db,
				auth: $auth,
				auditTrail: $auditTrail,
				versionService: ($versionService ?? $this->createMock(SecretVersionService::class)),
				guards: new ShareSyncGuards(targets: $this->mapper, secrets: $this->secretMapper, suites: $this->suiteMapper, auth: $auth),
				contexts: new ShareWriteContext(targets: $this->mapper, suites: $this->suiteMapper, auth: $auth),
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
	 * The client-supplied-id share-create surface is retired
	 * (GHSA-m5p9-6pw7-pf3g): a caller can no longer hand the server a
	 * `recipientSecretId`, so a share can never point at a secret the server
	 * did not mint for the recipient. Shares are created only through
	 * DirectShareRegistrar (register-batch), which mints each copy itself.
	 *
	 * @return void
	 */
	public function testCreateShareSurfaceIsRetired(): void {
		$this->assertFalse(
			method_exists(ShareService::class, 'createShare'),
			'createShare must be retired so recipientSecretId can never be client-supplied'
		);
		$this->assertFalse(
			method_exists(ShareService::class, 'createBatchShares'),
			'createBatchShares must be retired with createShare'
		);
	}//end testCreateShareSurfaceIsRetired()

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
		$entity->setTargetUserId('bob');

		$source = $this->makeOwnerSecret('src-1', 'alice');
		$copy = $this->makeOwnerSecret('copy-1', 'bob');

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
		$bob->setTargetUserId('bob');
		$carol = new ShareTarget();
		$carol->setSourceSecretId('src-1');
		$carol->setSecretId('copy-carol');
		$carol->setTargetUserId('carol');
		$this->mapper->method('findBySourceSecret')->with('src-1')->willReturn([$bob, $carol]);

		$copyBob = $this->makeOwnerSecret('copy-bob', 'bob');
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

		$copy1 = $this->makeOwnerSecret('copy-1', 'bob');
		$copy1->setPossiblyCompromisedAt(new DateTime('2026-01-01'));
		$copy2 = $this->makeOwnerSecret('copy-2', 'carol');

		$this->secretMapper->method('findById')->willReturnMap(
			[
				['src-1',  $source],
				['copy-1', $copy1],
				['copy-2', $copy2],
			]
		);

		// The membership guard (folder-permission-grades §2.3) only
		// writes ACTUAL recipient copies of the source, and the ownership
		// guard (GHSA-m5p9-6pw7-pf3g) only a copy held by its target.
		$row1 = new ShareTarget();
		$row1->setSourceSecretId('src-1');
		$row1->setSecretId('copy-1');
		$row1->setTargetUserId('bob');
		$row2 = new ShareTarget();
		$row2->setSourceSecretId('src-1');
		$row2->setSecretId('copy-2');
		$row2->setTargetUserId('carol');
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
		$copy = $this->makeOwnerSecret('copy-1', 'bob');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');
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
		$copy = $this->makeOwnerSecret('copy-1', 'bob');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');
		$this->mapper->method('findBySourceSecret')->willReturn([$row]);
		$this->db->expects($this->never())->method('beginTransaction');
		$sync = new ShareSyncService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			db: $this->db,
			auth: $this->createMock(ShareAuthorizationService::class),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
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
			savedAt: $at,
			writerId: 'alice'
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

		$copy = $this->makeOwnerSecret('copy-1', 'bob');
		$copy->setIsFavourite(true);
		$copy->setLastUsedAt(new DateTime('2026-09-30T08:00:00+00:00'));
		// As loaded from the database: nothing pending.
		$copy->resetUpdatedFields();

		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');
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
		$copy = $this->makeOwnerSecret('copy-1', 'bob');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$row->setTargetUserId('bob');
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
		// No existing shares, and every source is an original (no copy row).
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException('no row'));
		$this->mapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('original'));

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
		// The source is an original (no copy row).
		$this->mapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('original'));

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
		// The source is an original (no copy row).
		$this->mapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('original'));
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
	 * @param SecretVersionService|null $versionService The sync's version history
	 *
	 * @return ShareService
	 */
	private function gradedService(
		?string $grade,
		?ShareAuditTrail $auditTrail = null,
		?SecretVersionService $versionService = null,
	): ShareService {
		$teamFolderService = $this->createMock(originalClassName: TeamFolderService::class);
		$teamFolderService->method('resolveGrade')->willReturn($grade);

		return $this->wireService(teamFolderService: $teamFolderService, auditTrail: $auditTrail, versionService: $versionService);
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
		$copy = $this->makeOwnerSecret('copy-1', 'dan');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-1', $copy]]);
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-1');
		$row->setTargetUserId('dan');
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
	 * A delegate's sync keeps the source's value before it as a version, with
	 * the delegate as its actor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-prior-encrypted-values-are-retained-on-update
	 */
	public function testADelegatesSyncKeepsTheSourcesPreviousValueAsAVersion(): void {
		$source = $this->makeOwnerSecret('src-1', 'alice');
		$source->setKey('OLD');
		$this->secretMapper->method('findById')->willReturn($source);
		$this->mapper->method('findBySourceSecret')->willReturn([]);
		$this->delegationMapper->method('findActiveBySecretAndUser')
			->with('src-1', 'carol')
			->willReturn(new SecretDelegation());
		$versions = $this->createPartialMock(SecretVersionService::class, ['snapshot']);
		$versions->expects($this->once())->method('snapshot')
			->with(
				$this->callback(static fn (Secret $preUpdate): bool => $preUpdate->getId() === 'src-1' && $preUpdate->getKey() === 'OLD'),
				'user',
				'carol'
			);

		$this->gradedService(grade: null, versionService: $versions)->syncUpdate(
			secretId: 'src-1',
			updates: [['secretId' => 'src-1', 'key' => 'NEW']],
			expectedUpdatedAt: '',
			userId: 'carol'
		);
	}//end testADelegatesSyncKeepsTheSourcesPreviousValueAsAVersion()

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

	/**
	 * A ShareService whose authorization reads share rows, as the container
	 * wires it. Alice owns src-1; dave is an active delegate of it and holds
	 * his own direct copy, use-only or full; bob holds a use-only direct copy.
	 *
	 * @param bool                          $daveUseOnly  Whether dave's own copy is use-only
	 * @param DateTime|null                 $daveEndsAt   The end date of dave's own share
	 * @param ShareGrantReader|null         $grants       Judges dave's grants that have not ended
	 *
	 * @return ShareService
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The scenario switch.
	 */
	private function delegateScenario(
		bool $daveUseOnly,
		?DateTime $daveEndsAt = null,
		?ShareGrantReader $grants = null,
	): ShareService {
		$rows = [
			'dave' => $this->directRow(id: 'row-dave', target: 'dave', useOnly: $daveUseOnly),
			'bob' => $this->directRow(id: 'row-bob', target: 'bob', useOnly: true),
		];
		$rows['dave']->setExpiresAt($daveEndsAt);
		$secrets = [
			'src-1' => $this->makeOwnerSecret(id: 'src-1', ownerId: 'alice'),
			'copy-dave' => $this->makeOwnerSecret(id: 'copy-dave', ownerId: 'dave'),
			'copy-bob' => $this->makeOwnerSecret(id: 'copy-bob', ownerId: 'bob'),
		];
		$secrets['copy-dave']->setUseOnly($daveUseOnly);
		$secrets['copy-bob']->setUseOnly(true);

		$this->mapper->method('findById')->willReturnCallback(
			static fn (string $id): ShareTarget => ($rows[substr($id, 4)] ?? throw new DoesNotExistException('none'))
		);
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			static fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($rows[$targetUserId] ?? throw new DoesNotExistException('none'))
		);
		$this->secretMapper->method('findById')->willReturnCallback(
			static fn (string $id): Secret => ($secrets[$id] ?? throw new DoesNotExistException('none'))
		);
		$this->delegationMapper->method('findActiveBySecretAndUser')->willReturnCallback(
			static fn (string $secretId, string $userId): SecretDelegation => ($secretId === 'src-1' && $userId === 'dave'
				? new SecretDelegation()
				: throw new DoesNotExistException('none'))
		);

		return new ShareService(
			mapper: $this->mapper,
			auth: new ShareAuthorizationService(
				secretMapper: $this->secretMapper,
				delegationMapper: $this->delegationMapper,
				shareTargetMapper: $this->mapper,
				grants: $grants,
			),
			directRegistrar: $this->createMock(DirectShareRegistrar::class),
			syncService: $this->createMock(ShareSyncService::class),
			revocationService: $this->createMock(ShareRevocationService::class),
		);
	}//end delegateScenario()

	/**
	 * A direct share row of src-1.
	 *
	 * @param string $id      The row id
	 * @param string $target  The recipient
	 * @param bool   $useOnly Whether the share is use-only
	 *
	 * @return ShareTarget
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A fixture field.
	 */
	private function directRow(string $id, string $target, bool $useOnly): ShareTarget {
		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId('src-1');
		$row->setTargetUserId($target);
		$row->setSecretId('copy-'.$target);
		$row->setUseOnly($useOnly);
		return $row;
	}//end directRow()

	/**
	 * A delegate whose own copy is use-only cannot lift it through
	 * PATCH /shares/{id} (GHSA-m5p9-6pw7-pf3g): the copy stays use-only.
	 *
	 * @return void
	 */
	public function testADelegateCannotLiftTheirOwnUseOnlyShare(): void {
		$service = $this->delegateScenario(daveUseOnly: true);
		$this->mapper->expects($this->never())->method('update');

		try {
			$service->updateRestriction(shareId: 'row-dave', restriction: new ShareRestriction(useOnly: false), userId: 'dave');
			$this->fail('dave lifted his own use-only share');
		} catch (InvalidArgumentException) {
			$this->assertTrue($this->secretMapper->findById('copy-dave')->getUseOnly());
		}
	}//end testADelegateCannotLiftTheirOwnUseOnlyShare()

	/**
	 * A delegate whose own copy is use-only cannot lift anyone else's
	 * restriction either, as that would turn a restricted grant into a full
	 * copy for someone else.
	 *
	 * @return void
	 */
	public function testADelegateWithAUseOnlyCopyCannotLiftAnotherRecipientsShare(): void {
		$service = $this->delegateScenario(daveUseOnly: true);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(Secret::ONWARD_SHARE_REFUSAL);
		$service->updateRestriction(shareId: 'row-bob', restriction: new ShareRestriction(useOnly: false), userId: 'dave');
	}//end testADelegateWithAUseOnlyCopyCannotLiftAnotherRecipientsShare()

	/**
	 * A delegate never changes a share made to themselves, even when their
	 * copy is full.
	 *
	 * @return void
	 */
	public function testADelegateCannotChangeAShareMadeToThem(): void {
		$service = $this->delegateScenario(daveUseOnly: false);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('You cannot change a share made to you');
		$service->updateRestriction(shareId: 'row-dave', restriction: new ShareRestriction(useOnly: false), userId: 'dave');
	}//end testADelegateCannotChangeAShareMadeToThem()

	/**
	 * A delegate with a full copy of their own may still change another
	 * recipient's share, as they could share a full copy anyway.
	 *
	 * @return void
	 */
	public function testADelegateWithAFullCopyMayChangeAnotherRecipientsShare(): void {
		$service = $this->delegateScenario(daveUseOnly: false);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$updated = $service->updateRestriction(shareId: 'row-bob', restriction: new ShareRestriction(useOnly: false), userId: 'dave');

		$this->assertFalse($updated->getUseOnly());
	}//end testADelegateWithAFullCopyMayChangeAnotherRecipientsShare()

	/**
	 * Dave's copy still reads as full because his full direct share ended an
	 * hour ago and the expiry job has not removed it yet; the use-only group
	 * share he is in is all that is left, so he is judged use-only.
	 *
	 * @return void
	 */
	public function testADelegateWhoseFullGrantEndedIsJudgedOnTheGrantsLeft(): void {
		$groupShare = new GroupShare();
		$groupShare->setId('gs-1');
		$groupShare->setSecretId('src-1');
		$groupShare->setGroupId('team');
		$groupShare->setUseOnly(true);
		$this->groupShareMapper->method('findBySecret')->willReturn([$groupShare]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $userId, string $groupId): bool => $userId === 'dave' && $groupId === 'team');
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([]);

		$service = $this->delegateScenario(
			daveUseOnly: false,
			daveEndsAt: new DateTime('-1 hour'),
			grants: new ShareGrantReader(
				secretMapper: $this->secretMapper,
				groupShareMapper: $this->groupShareMapper,
				teamFolders: $teamFolders,
				groupManager: $groups,
			),
		);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(Secret::ONWARD_SHARE_REFUSAL);
		$service->updateRestriction(shareId: 'row-bob', restriction: new ShareRestriction(useOnly: false), userId: 'dave');
	}//end testADelegateWhoseFullGrantEndedIsJudgedOnTheGrantsLeft()

	/**
	 * Dave's full direct share ends tomorrow and the group share he is in is
	 * use-only without an end date. Combined per dimension his copy reads as
	 * full and permanent, but neither grant is, so he may not lift bob's
	 * restriction.
	 *
	 * @return void
	 */
	public function testADelegateWhoseGrantsAreOnlyUnrestrictedCombinedIsRefused(): void {
		$groupShare = new GroupShare();
		$groupShare->setId('gs-1');
		$groupShare->setSecretId('src-1');
		$groupShare->setGroupId('team');
		$groupShare->setUseOnly(true);
		$this->groupShareMapper->method('findBySecret')->willReturn([$groupShare]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $userId, string $groupId): bool => $userId === 'dave' && $groupId === 'team');
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([]);

		$service = $this->delegateScenario(
			daveUseOnly: false,
			daveEndsAt: new DateTime('+1 day'),
			grants: new ShareGrantReader(
				secretMapper: $this->secretMapper,
				groupShareMapper: $this->groupShareMapper,
				teamFolders: $teamFolders,
				groupManager: $groups,
			),
		);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(Secret::ONWARD_SHARE_REFUSAL);
		$service->updateRestriction(shareId: 'row-bob', restriction: new ShareRestriction(useOnly: false), userId: 'dave');
	}//end testADelegateWhoseGrantsAreOnlyUnrestrictedCombinedIsRefused()

	/**
	 * Bob owns his copy of alice's secret, so the owner check passes on it. A
	 * sync that names the copy is still refused and writes nothing, as it
	 * would change his copy alone (keepiq#1287).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
	 */
	public function testASyncThatNamesARecipientCopyIsRefused(): void {
		$copy = $this->makeOwnerSecret('copy-1', 'bob');
		$this->secretMapper->method('findById')->willReturn($copy);
		$shareRow = new ShareTarget();
		$shareRow->setSourceSecretId('src-1');
		$shareRow->setTargetUserId('bob');
		$shareRow->setSecretId('copy-1');
		$this->mapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$this->mapper->method('findByRecipientSecret')->with('copy-1')->willReturn($shareRow);
		$this->service = $this->wireService();

		$this->secretMapper->expects($this->never())->method('update');
		$this->secretMapper->expects($this->never())->method('advanceVersion');

		$this->expectException(ForbiddenException::class);
		$this->service->syncUpdate(
			secretId: 'copy-1',
			updates: [['secretId' => 'copy-1', 'key' => 'OTHER_VALUE']],
			expectedUpdatedAt: '',
			userId: 'bob'
		);
	}//end testASyncThatNamesARecipientCopyIsRefused()
}//end class
