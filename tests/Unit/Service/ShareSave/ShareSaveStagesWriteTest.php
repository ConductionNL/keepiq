<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writing a share-save stage: a new stage replaces the caller's old one, a
 * continued stage keeps its id and expiry, and every chunk records whose it is.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use DateTime;
use OCA\Keepiq\Db\ShareSaveStage;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Share-save stage writes and clean-up.
 */
#[CoversClass(ShareSaveStages::class)]
#[UsesClass(ShareSaveEntries::class)]
#[UsesClass(ShareSaveStage::class)]
class ShareSaveStagesWriteTest extends TestCase {

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * The entries one chunk carries.
	 *
	 * @return ShareSaveEntries
	 */
	private function entries(): ShareSaveEntries {
		return new ShareSaveEntries(
			copies: [['secretId' => 'c-1'], ['secretId' => 'c-2']],
			skipped: [['secretId' => 's-1']],
			federated: [['shareId' => 'f-1']],
			suspend: [],
		);
	}//end entries()

	/**
	 * A first chunk drops the caller's old stage, opens a new one and gives it thirty minutes.
	 *
	 * @return void
	 */
	public function testAFirstChunkOpensANewStage(): void {
		$inserted = null;
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->expects($this->once())->method('deleteByUserAndSecret')->with('alice', 'src');
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (ShareSaveStage $entity) use (&$inserted): ShareSaveStage {
				$inserted = $entity;
				return $entity;
			}
		);

		$base = new DateTime('2026-10-01T10:00:00+00:00');
		$sourceBase = new DateTime('2026-09-30T08:00:00+00:00');
		$before = time();
		$result = (new ShareSaveStages(mapper: $mapper))->add(
			stageId: null,
			userId: 'alice',
			secretId: 'src',
			base: $base,
			sourceBase: $sourceBase,
			entries: $this->entries(),
			expiresAt: null,
		);
		$after = time();

		$this->assertMatchesRegularExpression(self::UUID, $result['stageId']);
		$this->assertGreaterThanOrEqual($before + 1800, $result['expiresAt']->getTimestamp());
		$this->assertLessThanOrEqual($after + 1800, $result['expiresAt']->getTimestamp());

		$this->assertInstanceOf(ShareSaveStage::class, $inserted);
		$this->assertMatchesRegularExpression(self::UUID, $inserted->getId());
		$this->assertNotSame($result['stageId'], $inserted->getId());
		$this->assertSame($result['stageId'], $inserted->getStageId());
		$this->assertSame(['alice', 'src'], [$inserted->getUserId(), $inserted->getSecretId()]);
		$this->assertSame($base->getTimestamp(), $inserted->getBaseUpdatedAt()->getTimestamp());
		$this->assertNotSame($base, $inserted->getBaseUpdatedAt());
		$this->assertSame($sourceBase, $inserted->getSourceBaseUpdatedAt());
		$this->assertSame($this->entries()->toJson(), $inserted->getEntries());
		$this->assertSame([3, 1], [$inserted->getCopyCount(), $inserted->getFederatedCount()]);
		$this->assertSame($result['expiresAt']->getTimestamp(), $inserted->getExpiresAt()->getTimestamp());
		$this->assertNotSame($result['expiresAt'], $inserted->getExpiresAt());
		$this->assertGreaterThanOrEqual($before, $inserted->getCreatedAt()->getTimestamp());
	}//end testAFirstChunkOpensANewStage()

	/**
	 * A later chunk joins the named stage, keeps its expiry and drops nothing.
	 *
	 * @return void
	 */
	public function testALaterChunkJoinsTheStage(): void {
		$inserted = null;
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->expects($this->never())->method('deleteByUserAndSecret');
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (ShareSaveStage $entity) use (&$inserted): ShareSaveStage {
				$inserted = $entity;
				return $entity;
			}
		);

		$expiresAt = new DateTime('2026-10-01T10:30:00+00:00');
		$result = (new ShareSaveStages(mapper: $mapper))->add(
			stageId: 'stage-1',
			userId: 'alice',
			secretId: 'src',
			base: new DateTime('2026-10-01T10:00:00+00:00'),
			sourceBase: null,
			entries: new ShareSaveEntries(),
			expiresAt: $expiresAt,
		);

		$this->assertSame(['stageId' => 'stage-1', 'expiresAt' => $expiresAt], $result);
		$this->assertSame('stage-1', $inserted->getStageId());
		$this->assertNull($inserted->getSourceBaseUpdatedAt());
		$this->assertSame([0, 0], [$inserted->getCopyCount(), $inserted->getFederatedCount()]);
		$this->assertSame('2026-10-01T10:30:00+00:00', $inserted->getExpiresAt()->format(DATE_ATOM));
	}//end testALaterChunkJoinsTheStage()

	/**
	 * A stage id without an expiry is not trusted: a new stage is opened.
	 *
	 * @return void
	 */
	public function testAStageIdWithoutExpiryOpensANewStage(): void {
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->expects($this->once())->method('deleteByUserAndSecret')->with('alice', 'src');
		$mapper->method('insert')->willReturnArgument(0);

		$result = (new ShareSaveStages(mapper: $mapper))->add(
			stageId: 'stage-1',
			userId: 'alice',
			secretId: 'src',
			base: new DateTime(),
			sourceBase: null,
			entries: new ShareSaveEntries(),
			expiresAt: null,
		);

		$this->assertNotSame('stage-1', $result['stageId']);
		$this->assertMatchesRegularExpression(self::UUID, $result['stageId']);
	}//end testAStageIdWithoutExpiryOpensANewStage()

	/**
	 * Dropping a stage and the expired stages goes to the mapper and reports its count.
	 *
	 * @return void
	 */
	public function testDropAndDropExpiredDelegate(): void {
		$now = new DateTime('2026-10-01T12:00:00+00:00');
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->expects($this->once())->method('deleteByStage')->with('stage-1')->willReturn(2);
		$mapper->expects($this->once())->method('deleteExpired')->with($now)->willReturn(5);
		$stages = new ShareSaveStages(mapper: $mapper);

		$stages->drop(stageId: 'stage-1');

		$this->assertSame(5, $stages->dropExpired(now: $now));
	}//end testDropAndDropExpiredDelegate()

	/**
	 * A stage with a source base loads for the same source base; its expiry falls back to now.
	 *
	 * @return void
	 */
	public function testAStageWithASourceBaseLoadsForTheSameOne(): void {
		$chunk = new ShareSaveStage();
		$chunk->setUserId('alice');
		$chunk->setSecretId('src');
		$chunk->setBaseUpdatedAt(new DateTime('2026-10-01T10:00:00+00:00'));
		$chunk->setSourceBaseUpdatedAt(new DateTime('2026-09-30T08:00:00+00:00'));
		$chunk->setEntries('{"copies":[{"secretId":"c-1"}],"skipped":[],"federated":[],"suspend":[]}');
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->expects($this->once())->method('findLiveByStage')->with('st', $this->isInstanceOf(DateTime::class))->willReturn([$chunk]);

		$before = time();
		$loaded = (new ShareSaveStages(mapper: $mapper))->load(
			stageId: 'st',
			userId: 'alice',
			secretId: 'src',
			base: new DateTime('2026-10-01T10:00:00+00:00'),
			sourceBase: new DateTime('2026-09-30T08:00:00+00:00'),
		);

		$this->assertSame(1, $loaded['chunks']);
		$this->assertSame([['secretId' => 'c-1']], $loaded['entries']->copies);
		$this->assertGreaterThanOrEqual($before, $loaded['expiresAt']->getTimestamp());
	}//end testAStageWithASourceBaseLoadsForTheSameOne()
}//end class
