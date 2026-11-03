<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use PHPUnit\Framework\TestCase;

/**
 * The fan-out writer's defence in depth (GHSA-m5p9-6pw7-pf3g): a sync may only
 * write the source row and copies genuinely held by their share target.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
 */
class ShareSyncServiceTest extends TestCase {
	use ShareRowJudgeFixture;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $mapper;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secretMapper;

	private ShareSyncService $sync;

	protected function setUp(): void {
		$this->mapper       = $this->createMock(ShareTargetMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->sync         = new ShareSyncService(
			rowJudge: $this->shareRowJudge(targets: $this->mapper, secrets: $this->secretMapper),
			secretMapper: $this->secretMapper,
			db: $this->createMock(IDBConnection::class),
			auth: $this->createMock(ShareAuthorizationService::class),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
		);
	}

	/**
	 * Build a secret with a user owner.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}

	/**
	 * Build a share row.
	 *
	 * @param string $copyId The linked copy
	 * @param string $target The recipient
	 *
	 * @return ShareTarget
	 */
	private function row(string $copyId, string $target): ShareTarget {
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId($copyId);
		$row->setTargetUserId($target);
		return $row;
	}

	/**
	 * A sync by the owner of src-1 whose share rows grow on every read: the
	 * first read returns none, each later one a row more. Returns the sync,
	 * how deep in a transaction each row was judged, and how often the
	 * source was locked.
	 *
	 * @return array{0:ShareSyncService,1:\ArrayObject<string,int>,2:\ArrayObject<int,int>}
	 */
	private function syncWhoseRowsAppearUnderTheLock(): array {
		$source = $this->secret('src-1', 'alice');
		$source->setUpdatedAt(new DateTime('2026-11-01T00:00:00+00:00'));
		$depth   = new \ArrayObject([0]);
		$judgedAt = new \ArrayObject();
		$locks   = new \ArrayObject([0]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(static function () use ($depth): void {
			$depth[0]++;
		});
		$db->method('commit')->willReturnCallback(static function () use ($depth): void {
			$depth[0]--;
		});
		$db->method('rollBack')->willReturnCallback(static function () use ($depth): void {
			$depth[0]--;
		});

		$reads = 0;
		$this->mapper->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('findBySourceSecret')->willReturnCallback(function () use (&$reads): array {
			$rows = [];
			$reads++;
			for ($i = 1; $i < $reads; $i++) {
				$row = $this->row('copy-'.$i, 'bob');
				$row->setId('row-'.$i);
				$rows[] = $row;
			}

			return $rows;
		});
		$this->secretMapper->method('advanceVersion')->willReturnCallback(static function () use ($locks): int {
			$locks[0]++;
			return 1;
		});

		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturnCallback(static function (ShareTarget $row) use ($depth, $judgedAt): ?string {
			$judgedAt[(string)$row->getId()] = $depth[0];
			return null;
		});
		$judge = $this->shareRowJudge(targets: $this->mapper, secrets: $this->secretMapper, scanner: $scanner);

		$auth = $this->createMock(ShareAuthorizationService::class);
		$auth->method('loadSecret')->willReturn($source);
		$guards = $this->createMock(ShareSyncGuards::class);
		$guards->method('judgedRowsOf')->willReturnCallback(
			static fn (Secret $source, ?JudgedShareRows $before = null): JudgedShareRows => $judge->rowsOfSource(sourceId: $source->getId(), before: $before)
		);
		$guards->method('assertCopiesJudged')->willReturnCallback(
			static function (Secret $source, array $updates, ?string $writesOn, JudgedShareRows $judged): void {
				$judged->heldBack();
			}
		);
		$contexts = $this->createMock(ShareWriteContext::class);
		$contexts->method('standingOn')->willReturn(['grade' => 'owner', 'writesOn' => null]);
		$contexts->method('writesForEveryone')->willReturn(true);

		$sync = new ShareSyncService(
			rowJudge: $judge,
			secretMapper: $this->secretMapper,
			db: $db,
			auth: $auth,
			versionService: $this->createMock(SecretVersionService::class),
			guards: $guards,
			contexts: $contexts,
		);

		return [$sync, $judgedAt, $locks];
	}//end syncWhoseRowsAppearUnderTheLock()

	/**
	 * A row that appears under the source's lock is judged outside the
	 * transaction, so a quarantine the judging makes is never rolled back
	 * with it; the sync then locks the source again and goes on.
	 *
	 * @return void
	 */
	public function testARowThatAppearsUnderTheLockIsJudgedOutsideTheTransaction(): void {
		[$sync, $judgedAt, $locks] = $this->syncWhoseRowsAppearUnderTheLock();

		$sync->syncUpdate(secretId: 'src-1', updates: [], expectedUpdatedAt: '', userId: 'alice');

		$this->assertSame(0, $judgedAt['row-1']);
		$this->assertSame(0, $judgedAt['row-2']);
		$this->assertSame(3, $locks[0]);
	}//end testARowThatAppearsUnderTheLockIsJudgedOutsideTheTransaction()

	/**
	 * Rows that keep appearing do not hold a sync off for ever: on its last
	 * attempt it judges the row that appeared inside the transaction.
	 *
	 * @return void
	 */
	public function testTheLastAttemptJudgesARowThatAppearedInsideTheTransaction(): void {
		[$sync, $judgedAt] = $this->syncWhoseRowsAppearUnderTheLock();

		$sync->syncUpdate(secretId: 'src-1', updates: [], expectedUpdatedAt: '', userId: 'alice');

		$this->assertSame(1, $judgedAt['row-3']);
		$this->assertArrayNotHasKey('row-4', $judgedAt->getArrayCopy());
	}//end testTheLastAttemptJudgesARowThatAppearedInsideTheTransaction()

	/**
	 * A copy whose share row names a DIFFERENT owner than the copy actually has
	 * (a poisoned or drifted secret_id) is skipped, while the source row and a
	 * genuinely held copy are written.
	 *
	 * @return void
	 */
	public function testACopyNotHeldByItsTargetIsNotWritten(): void {
		$source     = $this->secret('src-1', 'alice');
		$heldCopy   = $this->secret('copy-held', 'bob');
		$victimCopy = $this->secret('victim', 'carol');

		$this->secretMapper->method('findById')->willReturnMap(
			[
				['src-1', $source],
				['copy-held', $heldCopy],
				['victim', $victimCopy],
			]
		);

		// The victim row claims bob holds it, but the secret is owned by carol.
		$this->mapper->method('findBySourceSecret')->willReturn(
			[$this->row('copy-held', 'bob'), $this->row('victim', 'bob')]
		);

		$written = [];
		$this->secretMapper->method('update')->willReturnCallback(
			static function (Secret $secret) use (&$written): Secret {
				$written[] = $secret->getId();
				return $secret;
			}
		);

		$count = $this->sync->writeCopyBlobs(
			source: $source,
			updates: [
				['secretId' => 'src-1', 'key' => 'NEW_SRC'],
				['secretId' => 'copy-held', 'key' => 'NEW_HELD'],
				['secretId' => 'victim', 'key' => 'EVIL'],
			],
			allowedIds: ['src-1' => true, 'copy-held' => true, 'victim' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(2, $count);
		$this->assertEqualsCanonicalizing(['src-1', 'copy-held'], $written);
		$this->assertNotContains('victim', $written);
	}


	/**
	 * A copy whose row is gone entirely is also skipped (there is nothing to
	 * vouch that the recipient holds it).
	 *
	 * @return void
	 */
	public function testACopyWithNoShareRowIsNotWritten(): void {
		$source = $this->secret('src-1', 'alice');
		$orphan = $this->secret('orphan', 'bob');

		$this->secretMapper->method('findById')->willReturnMap(
			[['src-1', $source], ['orphan', $orphan]]
		);
		$this->mapper->method('findBySourceSecret')->willReturn([]);

		$this->secretMapper->expects($this->never())->method('update');

		$count = $this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'orphan', 'key' => 'EVIL']],
			allowedIds: ['orphan' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(0, $count);
	}

	/**
	 * The TOTP seed and passkeys are written like the other value fields:
	 * a sent value is set, a null one cleared, an absent one left as it is.
	 *
	 * @return void
	 */
	public function testTheTotpAndPasskeysAreWrittenOnlyWhenSent(): void {
		$source = $this->secret('src-1', 'alice');
		$source->setTotp('OLD-T');
		$source->setPasskeys('OLD-P');
		$copy = $this->secret('copy-held', 'bob');
		$copy->setTotp('OLD-T-BOB');
		$copy->setPasskeys('OLD-P-BOB');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-held', $copy]]);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('copy-held', 'bob')]);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$this->sync->writeCopyBlobs(
			source: $source,
			updates: [
				['secretId' => 'src-1', 'key' => 'K'],
				['secretId' => 'copy-held', 'totp' => 'T-BOB', 'passkeys' => null],
			],
			allowedIds: ['src-1' => true, 'copy-held' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(['OLD-T', 'OLD-P'], [$source->getTotp(), $source->getPasskeys()]);
		$this->assertSame(['T-BOB', null], [$copy->getTotp(), $copy->getPasskeys()]);
	}

	/**
	 * An empty totp or passkeys clears the copy to null, as the head stores a
	 * cleared field, so resending it unchanged is no value change.
	 *
	 * @return void
	 */
	public function testAnEmptyTotpOrPasskeysLeavesTheCopyNull(): void {
		$source = $this->secret('src-1', 'alice');
		$copy = $this->secret('copy-held', 'bob');
		$copy->setTotp('OLD-T-BOB');
		$copy->setPasskeys('OLD-P-BOB');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-held', $copy]]);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('copy-held', 'bob')]);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'copy-held', 'key' => 'K', 'totp' => '', 'passkeys' => '']],
			allowedIds: ['copy-held' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertNull($copy->getTotp());
		$this->assertNull($copy->getPasskeys());
	}

	/**
	 * On /sync a copy row that leaves out a TOTP the source no longer holds
	 * clears the copy's seed instead of keeping it; a row whose totp does not
	 * follow the source's is refused.
	 *
	 * @return void
	 */
	public function testASyncRowFollowsTheSourcesTotp(): void {
		$guards = new ShareSyncGuards(
			rowJudge: $this->shareRowJudge(targets: $this->mapper, secrets: $this->secretMapper),
			secrets: $this->secretMapper,
			suites: $this->createMock(\OCA\Keepiq\Db\EncryptionSuiteMapper::class),
			auth: $this->createMock(ShareAuthorizationService::class),
		);
		$source = $this->secret('src-1', 'alice');
		$copy = $this->secret('copy-held', 'bob');
		$copy->setTotp('OWNER-REMOVED-THIS');
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-held', $copy]]);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('copy-held', 'bob')]);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$updates = $guards->mirroredValueFields(source: $source, updates: [['secretId' => 'copy-held', 'key' => 'K', 'login' => 'L']]);
		$this->sync->writeCopyBlobs(
			source: $source,
			updates: $updates,
			allowedIds: ['copy-held' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);
		$this->assertNull($copy->getTotp());

		$source->setTotp('T');
		$this->expectException(\InvalidArgumentException::class);
		$guards->mirroredValueFields(source: $source, updates: [['secretId' => 'copy-held', 'key' => 'K', 'totp' => null]]);
	}

	/**
	 * A copy whose key and TOTP seed are replaced in separate syncs loses its
	 * warning with the second, as the head does: the first moved its key age.
	 *
	 * @return void
	 */
	public function testACopysWarningClearsWhenKeyAndTotpRotateInSeparateSyncs(): void {
		$source = $this->secret('src-1', 'alice');
		$copy = $this->secret('copy-held', 'bob');
		$copy->setKey('OLD-K');
		$copy->setTotp('OLD-T');
		$copy->setKeyUpdatedAt(new DateTime('2026-09-01T00:00:00+00:00'));
		$copy->setPossiblyCompromisedAt(new DateTime('2026-10-01T00:00:00+00:00'));
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-held', $copy]]);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('copy-held', 'bob')]);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$write = fn (array $update, string $at) => $this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'copy-held'] + $update],
			allowedIds: ['copy-held' => true],
			savedAt: new DateTime($at),
			writerId: 'alice'
		);

		$write(['key' => 'NEW-K'], '2026-10-21T00:00:00+00:00');
		$this->assertNotNull($copy->getPossiblyCompromisedAt());
		$this->assertSame('2026-10-21', $copy->getKeyUpdatedAt()?->format('Y-m-d'));

		$write(['totp' => 'NEW-T'], '2026-10-22T00:00:00+00:00');
		$this->assertNull($copy->getPossiblyCompromisedAt());
	}

	/**
	 * A flagged copy keeps its possibly-compromised warning while a credential
	 * it holds is left as it was, and loses it once all are rewritten.
	 *
	 * @return void
	 */
	public function testACopysWarningClearsOnlyWhenNoCredentialIsLeftExposed(): void {
		$source = $this->secret('src-1', 'alice');
		$copy = $this->secret('copy-held', 'bob');
		$copy->setKey('OLD-K');
		$copy->setTotp('OLD-T');
		$copy->setPossiblyCompromisedAt(new DateTime('2026-10-01T00:00:00+00:00'));
		$this->secretMapper->method('findById')->willReturnMap([['src-1', $source], ['copy-held', $copy]]);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('copy-held', 'bob')]);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$write = fn (array $update) => $this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'copy-held'] + $update],
			allowedIds: ['copy-held' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$write(['key' => 'NEW-K', 'login' => 'L']);
		$this->assertNotNull($copy->getPossiblyCompromisedAt(), 'the TOTP seed is still the exposed one');

		$write(['key' => 'NEWER-K', 'totp' => 'NEW-T']);
		$this->assertNull($copy->getPossiblyCompromisedAt());
	}

	/**
	 * A copy named by the update but already deleted is silently skipped.
	 *
	 * @return void
	 */
	public function testAMissingCopyIsSkipped(): void {
		$source = $this->secret('src-1', 'alice');
		$this->secretMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($source): Secret {
				if ($id === 'src-1') {
					return $source;
				}

				throw new DoesNotExistException('gone');
			}
		);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('gone', 'bob')]);
		$this->secretMapper->expects($this->never())->method('update');

		$count = $this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'gone', 'key' => 'X']],
			allowedIds: ['gone' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(0, $count);
	}
}
