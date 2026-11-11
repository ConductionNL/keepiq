<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of loading a share-save stage: whose stage it is, and
 * what its chunks hold together.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use DateTime;
use OCA\Keepiq\Db\ShareSaveStage;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use PHPUnit\Framework\TestCase;

/**
 * Share-save stage loading outcomes.
 */
class ShareSaveStagesTest extends TestCase {

	private const BASE = '2026-10-01T10:00:00+00:00';

	/**
	 * A chunk of alice's stage for src.
	 *
	 * @param array<string,list<mixed>> $lists The chunk's lists
	 *
	 * @return ShareSaveStage
	 */
	private function chunk(array $lists): ShareSaveStage {
		$chunk = new ShareSaveStage();
		$chunk->setUserId('alice');
		$chunk->setSecretId('src');
		$chunk->setBaseUpdatedAt(new DateTime(self::BASE));
		$chunk->setEntries((string)json_encode($lists + ['copies' => [], 'skipped' => [], 'federated' => [], 'suspend' => []]));
		$chunk->setExpiresAt(new DateTime('2026-10-01T11:00:00+00:00'));
		return $chunk;
	}//end chunk()

	/**
	 * A service over the given chunks.
	 *
	 * @param list<ShareSaveStage> $chunks The live chunks
	 *
	 * @return ShareSaveStages
	 */
	private function stages(array $chunks): ShareSaveStages {
		$mapper = $this->createMock(ShareSaveStageMapper::class);
		$mapper->method('findLiveByStage')->willReturn($chunks);
		return new ShareSaveStages(mapper: $mapper);
	}//end stages()

	/**
	 * The chunks' lists are merged in order, the skips and suspensions included.
	 *
	 * @return void
	 */
	public function testTheChunksAreMergedInOrder(): void {
		$stages = $this->stages([
			$this->chunk(['copies' => [['secretId' => 'c-1']], 'skipped' => [['secretId' => 's-1']]]),
			$this->chunk(['copies' => [['secretId' => 'c-2']], 'federated' => [['shareId' => 'f-1']], 'suspend' => [['shareId' => 'f-2']], 'skipped' => [['secretId' => 's-2']]]),
		]);

		$loaded = $stages->load(stageId: 'st', userId: 'alice', secretId: 'src', base: new DateTime(self::BASE), sourceBase: null);

		$this->assertSame(2, $loaded['chunks']);
		$this->assertSame('2026-10-01T11:00:00+00:00', $loaded['expiresAt']->format(DATE_ATOM));
		$this->assertSame([['secretId' => 'c-1'], ['secretId' => 'c-2']], $loaded['entries']->copies);
		$this->assertSame([['secretId' => 's-1'], ['secretId' => 's-2']], $loaded['entries']->skipped);
		$this->assertSame([['shareId' => 'f-1']], $loaded['entries']->federated);
		$this->assertSame([['shareId' => 'f-2']], $loaded['entries']->suspend);
	}//end testTheChunksAreMergedInOrder()

	/**
	 * A stage is refused for another user, another row, another base or another source base.
	 *
	 * @return void
	 */
	public function testAStageIsRefusedForAnyOtherCaller(): void {
		$stages = $this->stages([$this->chunk([])]);
		$cases = [
			'another user' => ['bob', 'src', self::BASE, null],
			'another row' => ['alice', 'c-1', self::BASE, null],
			'another base' => ['alice', 'src', '2026-10-01T10:00:01+00:00', null],
			'a source base' => ['alice', 'src', self::BASE, self::BASE],
		];
		foreach ($cases as $name => [$userId, $secretId, $base, $sourceBase]) {
			try {
				$stages->load(
					stageId: 'st',
					userId: $userId,
					secretId: $secretId,
					base: new DateTime($base),
					sourceBase: $sourceBase === null ? null : new DateTime($sourceBase)
				);
				$this->fail($name . ' loaded the stage');
			} catch (ShareSaveRefusal $refusal) {
				$this->assertSame(['stage_mismatch', 409], [$refusal->getError(), $refusal->getStatus()], $name);
			}
		}
	}//end testAStageIsRefusedForAnyOtherCaller()

	/**
	 * A stage without live chunks is unknown.
	 *
	 * @return void
	 */
	public function testAStageWithoutLiveChunksIsUnknown(): void {
		try {
			$this->stages([])->load(stageId: 'st', userId: 'alice', secretId: 'src', base: new DateTime(self::BASE), sourceBase: null);
			$this->fail('loaded an empty stage');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame(['stage_unknown', 404, 'The stage does not exist or expired'], [$refusal->getError(), $refusal->getStatus(), $refusal->getMessage()]);
		}
	}//end testAStageWithoutLiveChunksIsUnknown()
}//end class
