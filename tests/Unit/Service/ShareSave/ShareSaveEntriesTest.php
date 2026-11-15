<?php

/**
 * Unit tests for ShareSaveEntries.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The parsed entry lists: counting, id extraction, merging, the
 * duplicate guard and the stored JSON shape.
 */
#[CoversClass(ShareSaveEntries::class)]
#[UsesClass(ShareSaveRefusal::class)]
class ShareSaveEntriesTest extends TestCase {
	private static function entries(): ShareSaveEntries {
		return new ShareSaveEntries(
			copies: [['secretId' => 'c-1', 'key' => 'K'], ['secretId' => 'c-2', 'key' => 'K']],
			skipped: [['secretId' => 'c-3', 'reason' => 'no_vault_key']],
			federated: [['shareId' => 'f-1']],
			suspend: [['shareId' => 'f-2', 'reason' => 'x'], ['shareId' => 'f-3', 'reason' => 'x']],
		);
	}

	public function testCountsIncludeSkippedAndSuspended(): void {
		$entries = self::entries();

		$this->assertSame(3, $entries->copyCount());
		$this->assertSame(3, $entries->federatedCount());
		$this->assertSame(0, (new ShareSaveEntries())->copyCount());
		$this->assertSame(0, (new ShareSaveEntries())->federatedCount());
	}

	public function testIdsListCopiesBeforeSkippedAndSharesBeforeSuspended(): void {
		$entries = self::entries();

		$this->assertSame(['c-1', 'c-2', 'c-3'], $entries->copyIds());
		$this->assertSame(['f-1', 'f-2', 'f-3'], $entries->shareIds());
	}

	public function testMergeAppendsEachListAndLeavesBothOperandsUnchanged(): void {
		$left = new ShareSaveEntries(copies: [['secretId' => 'a']], suspend: [['shareId' => 's1']]);
		$right = new ShareSaveEntries(copies: [['secretId' => 'b']], skipped: [['secretId' => 'c']], federated: [['shareId' => 'f']]);

		$merged = $left->merge($right);

		$this->assertSame([['secretId' => 'a'], ['secretId' => 'b']], $merged->copies);
		$this->assertSame([['secretId' => 'c']], $merged->skipped);
		$this->assertSame([['shareId' => 'f']], $merged->federated);
		$this->assertSame([['shareId' => 's1']], $merged->suspend);
		$this->assertSame([['secretId' => 'a']], $left->copies);
		$this->assertSame([], $right->suspend);
	}

	public function testDistinctIdsPassTheDuplicateGuard(): void {
		self::entries()->assertNoDuplicates();
		$this->addToAssertionCount(1);
	}

	public function testACopyAlsoNamedAsSkippedIsADuplicate(): void {
		$entries = new ShareSaveEntries(copies: [['secretId' => 'c-1']], skipped: [['secretId' => 'c-1', 'reason' => 'no_vault_key']]);

		try {
			$entries->assertNoDuplicates();
			$this->fail('The duplicate was accepted');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('duplicate_entry', $refusal->getError());
			$this->assertSame('An entry is named twice', $refusal->getMessage());
		}
	}

	public function testAShareNamedTwiceIsADuplicate(): void {
		$entries = new ShareSaveEntries(federated: [['shareId' => 'f']], suspend: [['shareId' => 'f']]);

		$this->expectException(ShareSaveRefusal::class);
		$entries->assertNoDuplicates();
	}

	public function testTheSameIdAsCopyAndShareIsNotADuplicate(): void {
		(new ShareSaveEntries(copies: [['secretId' => 'x']], federated: [['shareId' => 'x']]))->assertNoDuplicates();
		$this->addToAssertionCount(1);
	}

	public function testToJsonKeepsTheListOrderAndShape(): void {
		$json = (new ShareSaveEntries(copies: [['secretId' => 'c', 'key' => 'K']]))->toJson();

		$this->assertSame('{"copies":[{"secretId":"c","key":"K"}],"skipped":[],"federated":[],"suspend":[]}', $json);
	}
}
