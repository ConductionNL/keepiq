<?php

/**
 * Unit tests for the refusals of ShareSaveScope.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\ShareSave\ShareSavePath;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A copy editor's save is refused when the source is gone, cannot be
 * written, or the editor may not write it, both when the path is resolved
 * and when it is re-checked before writing.
 *
 * @covers \OCA\Keepiq\Service\ShareSave\ShareSaveScope
 * @covers \OCA\Keepiq\Service\ShareSave\ShareSaveStages
 * @uses   \OCA\Keepiq\Service\ShareSave\ShareSavePath
 * @uses   \OCA\Keepiq\Service\ShareSave\ShareSaveRefusal
 * @uses   \OCA\Keepiq\Service\ShareRowVerdict
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
final class ShareSaveScopeRefusalsTest extends TestCase {

	/**
	 * The row judge.
	 *
	 * @var ShareRowJudge&MockObject
	 */
	private ShareRowJudge $judge;

	/**
	 * The secret mapper.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secrets;

	/**
	 * The sync service.
	 *
	 * @var ShareSyncService&MockObject
	 */
	private ShareSyncService $sync;

	/**
	 * The sync guards.
	 *
	 * @var ShareSyncGuards&MockObject
	 */
	private ShareSyncGuards $guards;

	/**
	 * The copy Bob holds, and its source.
	 *
	 * @var Secret
	 */
	private Secret $copy;

	/**
	 * The source.
	 *
	 * @var Secret
	 */
	private Secret $source;

	/**
	 * Fresh doubles; the judge trusts Bob's copy of the source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->judge = $this->createMock(ShareRowJudge::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->sync = $this->createMock(ShareSyncService::class);
		$this->guards = $this->createMock(ShareSyncGuards::class);
		$this->copy = $this->secret('copy-bob');
		$this->source = $this->secret('src-1');

		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-bob');
		$row->setTargetUserId('bob');
		$this->judge->method('forCopy')->willReturn(new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $row));
	}//end setUp()

	/**
	 * A trusted copy resolves to its source, writing on what the sync names.
	 *
	 * @return void
	 */
	public function testATrustedCopyResolvesToItsSource(): void {
		$this->secrets->method('findById')->with('src-1')->willReturn($this->source);
		$this->guards->expects($this->once())->method('assertWritable')->with($this->source);
		$this->sync->method('resolveSyncWriter')->with($this->source, 'bob')->willReturn('group-1');

		$path = $this->scope()->resolve($this->copy, 'bob');

		$this->assertSame($this->copy, $path->row);
		$this->assertSame($this->source, $path->source);
		$this->assertSame('group-1', $path->writesOn);
	}//end testATrustedCopyResolvesToItsSource()

	/**
	 * A source that is gone is not found, and nobody is asked to write it.
	 *
	 * @return void
	 */
	public function testAMissingSourceIsNotFound(): void {
		$this->secrets->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$this->sync->expects($this->never())->method('resolveSyncWriter');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Secret not found');

		$this->scope()->resolve($this->copy, 'bob');
	}//end testAMissingSourceIsNotFound()

	/**
	 * A source that cannot be written is forbidden, with the guard's reason.
	 *
	 * @return void
	 */
	public function testAnUnwritableSourceIsForbidden(): void {
		$this->secrets->method('findById')->willReturn($this->source);
		$this->guards->method('assertWritable')->willThrowException(new InvalidArgumentException('A use-only copy cannot be changed'));
		$this->sync->expects($this->never())->method('resolveSyncWriter');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('A use-only copy cannot be changed');

		$this->scope()->resolve($this->copy, 'bob');
	}//end testAnUnwritableSourceIsForbidden()

	/**
	 * An editor who may not write for every copy is refused with 403.
	 *
	 * @return void
	 */
	public function testAnEditorWhoMayNotWriteIsRefused(): void {
		$this->secrets->method('findById')->willReturn($this->source);
		$this->sync->method('resolveSyncWriter')->willThrowException(new InvalidArgumentException('no'));

		try {
			$this->scope()->resolve($this->copy, 'bob');
			$this->fail('Expected a refusal');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $refusal->getStatus());
			$this->assertSame('not_allowed', $refusal->getBody()['error']);
			$this->assertStringStartsWith('Only the owner, a delegate', $refusal->getBody()['message']);
		}
	}//end testAnEditorWhoMayNotWriteIsRefused()

	/**
	 * An owner's path is not re-checked.
	 *
	 * @return void
	 */
	public function testAnOwnersPathIsNotRechecked(): void {
		$path = new ShareSavePath(row: $this->source, source: $this->source);
		$this->secrets->expects($this->never())->method('findById');

		$this->assertSame($path, $this->scope()->assertStillWriter($path, 'alice'));
	}//end testAnOwnersPathIsNotRechecked()

	/**
	 * A copy editor's path is re-read: a source gone since is not found.
	 *
	 * @return void
	 */
	public function testARecheckFindsTheSourceGone(): void {
		$this->secrets->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Secret not found');

		$this->scope()->assertStillWriter(new ShareSavePath(row: $this->copy, source: $this->source), 'bob');
	}//end testARecheckFindsTheSourceGone()

	/**
	 * Access lost since resolving is refused with its own message.
	 *
	 * @return void
	 */
	public function testARecheckRefusesLostAccess(): void {
		$this->secrets->method('findById')->willReturn($this->secret('src-1'));
		$this->sync->method('resolveSyncWriter')->willThrowException(new InvalidArgumentException('no'));

		try {
			$this->scope()->assertStillWriter(new ShareSavePath(row: $this->copy, source: $this->source), 'bob');
			$this->fail('Expected a refusal');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $refusal->getStatus());
			$this->assertSame('Your access to this secret changed while saving; reload and try again', $refusal->getBody()['message']);
		}
	}//end testARecheckRefusesLostAccess()

	/**
	 * A passing re-check keeps the source as first read and takes the fresh writer.
	 *
	 * @return void
	 */
	public function testARecheckKeepsTheSourceAsFirstRead(): void {
		$fresh = $this->secret('src-1');
		$this->secrets->method('findById')->with('src-1')->willReturn($fresh);
		$this->guards->expects($this->once())->method('assertWritable')->with($fresh);
		$this->sync->method('resolveSyncWriter')->with($fresh, 'bob')->willReturn(null);

		$path = $this->scope()->assertStillWriter(new ShareSavePath(row: $this->copy, source: $this->source, writesOn: 'group-1'), 'bob');

		$this->assertSame($this->source, $path->source);
		$this->assertSame($this->copy, $path->row);
		$this->assertNull($path->writesOn);
	}//end testARecheckKeepsTheSourceAsFirstRead()

	/**
	 * Expired stages are dropped by the mapper, and the count is answered.
	 *
	 * @return void
	 */
	public function testExpiredStagesAreDropped(): void {
		$now = new DateTime('2026-11-13T10:00:00Z');
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->expects($this->once())->method('deleteExpired')->with($now)->willReturn(3);

		$this->assertSame(3, (new ShareSaveStages(mapper: $mapper))->dropExpired($now));
	}//end testExpiredStagesAreDropped()

	/**
	 * A copy whose share rows are ambiguous is not found, and its source is
	 * never read.
	 *
	 * @return void
	 */
	public function testAnAmbiguousCopyIsNotFound(): void {
		$this->judge = $this->createMock(ShareRowJudge::class);
		$this->judge->method('forCopy')->with($this->copy)->willReturn(new ShareRowVerdict(state: ShareRowVerdict::AMBIGUOUS));
		$this->secrets->expects($this->never())->method('findById');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Secret not found');

		$this->scope()->resolve($this->copy, 'bob');
	}//end testAnAmbiguousCopyIsNotFound()

	/**
	 * A secret with an id.
	 *
	 * @param string $id The id.
	 *
	 * @return Secret
	 */
	private function secret(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		return $secret;
	}//end secret()

	/**
	 * The scope under test.
	 *
	 * @return ShareSaveScope
	 */
	private function scope(): ShareSaveScope {
		return new ShareSaveScope(
			rowJudge: $this->judge,
			secrets: $this->secrets,
			federatedShares: $this->createMock(FederatedShareMapper::class),
			sync: $this->sync,
			guards: $this->guards,
		);
	}//end scope()
}//end class
