<?php

/**
 * The judged lookup of share rows on its own: which verdict each lookup
 * answers, that it fails closed, which verdicts count as a copy, and that a
 * list of rows asks the scan about each row once.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bob holds V; a row `{S_a, bob, V}` names it.
 */
class ShareRowJudgeTest extends TestCase {

	/**
	 * The row `{S_a, bob, V}`.
	 *
	 * @var ShareTarget
	 */
	private ShareTarget $row;

	/**
	 * Bob's secret V.
	 *
	 * @var Secret
	 */
	private Secret $copy;

	/**
	 * Seed the row and the copy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->row = new ShareTarget();
		$this->row->setId('row-V');
		$this->row->setSourceSecretId('S_a');
		$this->row->setTargetUserId('bob');
		$this->row->setSecretId('V');

		$this->copy = new Secret();
		$this->copy->setId('V');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
	}//end setUp()

	/**
	 * The scan's answers, and the verdict each gives on a row Bob holds.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function scanAnswers(): array {
		return [
			'cleared' => [null, ShareRowVerdict::TRUSTED],
			'quarantined' => [ShareQuarantineScanner::HELD_QUARANTINED, ShareRowVerdict::QUARANTINED],
			'unjudged' => [ShareQuarantineScanner::HELD_UNJUDGED, ShareRowVerdict::UNJUDGED],
			'an answer the scan never gives' => ['something-else', ShareRowVerdict::UNJUDGED],
		];
	}//end scanAnswers()

	/**
	 * A row Bob holds is trusted only when the scan clears it; an unknown
	 * answer fails closed as unjudged.
	 *
	 * @param mixed  $answer   The scan's answer
	 * @param string $expected The verdict
	 *
	 * @return void
	 */
	#[DataProvider('scanAnswers')]
	public function testOnlyAClearedRowIsTrusted(mixed $answer, string $expected): void {
		$verdict = $this->judge(answer: $answer)->forCopy(copy: $this->copy);

		$this->assertSame($expected, $verdict->state);
		$this->assertSame(($expected === ShareRowVerdict::TRUSTED ? $this->row : null), $verdict->trustedRow());
	}//end testOnlyAClearedRowIsTrusted()

	/**
	 * A row that carries a quarantine stamp is quarantined, even when the
	 * scan has completed and answers that it holds nothing back.
	 *
	 * @return void
	 */
	public function testAStampedRowIsQuarantinedWhateverTheScanSays(): void {
		$this->row->setQuarantinedAt(new DateTime());
		$judge = $this->judge(answer: null);

		$this->assertSame(ShareQuarantineScanner::HELD_QUARANTINED, $judge->verdict(row: $this->row));
		$this->assertSame(ShareRowVerdict::QUARANTINED, $judge->forCopy(copy: $this->copy)->state);
	}//end testAStampedRowIsQuarantinedWhateverTheScanSays()

	/**
	 * A row whose target does not hold the copy is still judged: the scan
	 * quarantining it makes it no copy; otherwise it stays foreign, and a
	 * check that refuses copies waits while the scan cannot judge it.
	 *
	 * @return void
	 */
	public function testAForeignRowIsJudgedToo(): void {
		$this->row->setTargetUserId('mallory');

		$quarantined = $this->judge(answer: ShareQuarantineScanner::HELD_QUARANTINED)->forCopy(copy: $this->copy);
		$this->assertSame(ShareRowVerdict::QUARANTINED, $quarantined->state);
		$this->assertFalse($quarantined->countsAsCopy());

		foreach ([null, ShareQuarantineScanner::HELD_UNJUDGED] as $answer) {
			$foreign = $this->judge(answer: $answer)->forCopy(copy: $this->copy);
			$this->assertSame(ShareRowVerdict::FOREIGN, $foreign->state);
			$this->assertNull($foreign->trustedRow());
			$this->assertTrue($foreign->countsAsCopy());
			$this->assertSame($answer === ShareQuarantineScanner::HELD_UNJUDGED, $foreign->awaitsTheScan());
		}
	}//end testAForeignRowIsJudgedToo()

	/**
	 * Two rows for one pair are ambiguous, and a pair whose copy is gone is
	 * foreign; neither is trusted.
	 *
	 * @return void
	 */
	public function testAPairLookupFailsClosed(): void {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecretAndTargetUser')->willThrowException(new MultipleObjectsReturnedException('two'));
		$ambiguous = $this->judge(answer: null, targets: $targets)->forPair(sourceId: 'S_a', targetUserId: 'bob');
		$this->assertSame(ShareRowVerdict::AMBIGUOUS, $ambiguous->state);
		$this->assertNull($ambiguous->trustedRow());

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$gone = $this->judge(answer: null, secrets: $secrets)->forPair(sourceId: 'S_a', targetUserId: 'bob');
		$this->assertSame(ShareRowVerdict::FOREIGN, $gone->state);
		$this->assertNull($gone->trustedRow());

		$this->assertSame(ShareRowVerdict::TRUSTED, $this->judge(answer: null)->forPair(sourceId: 'S_a', targetUserId: 'bob')->state);
	}//end testAPairLookupFailsClosed()

	/**
	 * Which verdicts count as a copy for a check that refuses copies.
	 *
	 * @return void
	 */
	public function testWhichVerdictsCountAsACopy(): void {
		$table = [];
		foreach ([
			ShareRowVerdict::NONE,
			ShareRowVerdict::AMBIGUOUS,
			ShareRowVerdict::FOREIGN,
			ShareRowVerdict::QUARANTINED,
			ShareRowVerdict::UNJUDGED,
			ShareRowVerdict::TRUSTED,
		] as $state) {
			$table[$state] = (new ShareRowVerdict(state: $state))->countsAsCopy();
		}

		$this->assertSame(
			[
				ShareRowVerdict::NONE => false,
				ShareRowVerdict::AMBIGUOUS => true,
				ShareRowVerdict::FOREIGN => true,
				ShareRowVerdict::QUARANTINED => false,
				ShareRowVerdict::UNJUDGED => true,
				ShareRowVerdict::TRUSTED => true,
			],
			$table
		);
	}//end testWhichVerdictsCountAsACopy()

	/**
	 * A list of rows asks the scan about a row once, however often a caller
	 * asks, and only about rows a caller asks about.
	 *
	 * @return void
	 */
	public function testAListAsksTheScanAboutEachRowOnce(): void {
		$other = new ShareTarget();
		$other->setId('row-other');
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->expects($this->once())->method('heldBack')->with($this->row)
			->willReturn(ShareQuarantineScanner::HELD_UNJUDGED);
		$rows = (new ShareRowJudge(
			targets: $this->createMock(ShareTargetMapper::class),
			secrets: $this->createMock(SecretMapper::class),
			scanner: $scanner
		))->judgeRows(rows: [$this->row, $other]);

		$this->assertSame(ShareQuarantineScanner::HELD_UNJUDGED, $rows->verdict(row: $this->row));
		$this->assertSame(ShareQuarantineScanner::HELD_UNJUDGED, $rows->verdict(row: $this->row));
		$this->assertSame([$this->row, $other], $rows->all());
	}//end testAListAsksTheScanAboutEachRowOnce()

	/**
	 * A judge whose scan gives one answer for every row.
	 *
	 * @param mixed                  $answer  The scan's answer
	 * @param ShareTargetMapper|null $targets The share targets, or the one row by copy and by pair
	 * @param SecretMapper|null      $secrets The secrets, or V alone
	 *
	 * @return ShareRowJudge
	 */
	private function judge(mixed $answer, ?ShareTargetMapper $targets = null, ?SecretMapper $secrets = null): ShareRowJudge {
		if ($targets === null) {
			$targets = $this->createMock(ShareTargetMapper::class);
			$targets->method('findByRecipientSecret')->willReturn($this->row);
			$targets->method('findBySourceSecretAndTargetUser')->willReturn($this->row);
		}

		if ($secrets === null) {
			$secrets = $this->createMock(SecretMapper::class);
			$secrets->method('findById')->willReturn($this->copy);
		}

		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturn($answer);

		return new ShareRowJudge(targets: $targets, secrets: $secrets, scanner: $scanner);
	}//end judge()
}//end class
