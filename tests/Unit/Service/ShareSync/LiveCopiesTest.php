<?php

/**
 * Keepiq - ShareSyncGuards::liveCopies tests
 *
 * Golden outcomes of which copies of a source a save for every copy writes:
 * a live copy held by its target is written; a copy whose access ended, whose
 * row is gone, whose row its target does not hold, or whose row the pending
 * quarantine scan holds back is ignored, and one the scan cannot judge is also
 * listed as unjudged; the caller's own row is left out of every list.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSync
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSync;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the live copies a save writes.
 */
class LiveCopiesTest extends TestCase {
	/**
	 * The secret mapper mock.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secrets;

	/**
	 * The row judge mock.
	 *
	 * @var ShareRowJudge&MockObject
	 */
	private ShareRowJudge&MockObject $rowJudge;

	/**
	 * The guards under test.
	 *
	 * @var ShareSyncGuards
	 */
	private ShareSyncGuards $guards;

	/**
	 * Build the guards over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secrets  = $this->createMock(SecretMapper::class);
		$this->rowJudge = $this->createMock(ShareRowJudge::class);
		$this->guards   = new ShareSyncGuards(
			$this->rowJudge,
			$this->secrets,
			$this->createMock(EncryptionSuiteMapper::class),
			$this->createMock(ShareAuthorizationService::class)
		);
	}//end setUp()

	/**
	 * A share row from the source to a copy held by a user.
	 *
	 * @param int    $id     The row id
	 * @param string $copyId The copy's secret id
	 * @param string $userId The target user
	 *
	 * @return ShareTarget
	 */
	private function target(int $id, string $copyId, string $userId): ShareTarget {
		$target = new ShareTarget();
		$target->setId($id);
		$target->setSourceSecretId('src');
		$target->setSecretId($copyId);
		$target->setTargetUserId($userId);
		return $target;
	}//end target()

	/**
	 * A copy row owned by a user.
	 *
	 * @param string        $copyId The copy's id
	 * @param string        $userId Its owner
	 * @param DateTime|null $ends   When its access ends
	 *
	 * @return Secret
	 */
	private function copy(string $copyId, string $userId, ?DateTime $ends=null): Secret {
		$copy = new Secret();
		$copy->setId($copyId);
		$copy->setOwnerType('user');
		$copy->setOwnerId($userId);
		$copy->setAccessExpiresAt($ends);
		return $copy;
	}//end copy()

	/**
	 * The source.
	 *
	 * @return Secret
	 */
	private function source(): Secret {
		$source = new Secret();
		$source->setId('src');
		return $source;
	}//end source()

	/**
	 * Every kind of copy lands in its list, in row order.
	 *
	 * @return void
	 */
	public function testSortsEveryKindOfCopy(): void {
		$targets = [
			$this->target(1, 'live', 'bob'),
			$this->target(2, 'ended', 'bob'),
			$this->target(3, 'gone', 'bob'),
			$this->target(4, 'drifted', 'bob'),
			$this->target(5, 'quarantined', 'bob'),
			$this->target(6, 'unjudged', 'carol'),
			$this->target(7, 'mine', 'me'),
			$this->target(8, 'later', 'dave'),
			$this->target(9, 'open-ended', 'erin'),
		];
		$verdicts = [5 => ShareQuarantineScanner::HELD_QUARANTINED, 6 => ShareQuarantineScanner::HELD_UNJUDGED];
		$judged   = new JudgedShareRows(
			$targets,
			static fn (ShareTarget $row): ?string => ($verdicts[$row->getId()] ?? null)
		);
		$this->secrets->expects($this->once())->method('findByIds')
			->with(['live', 'ended', 'gone', 'drifted', 'quarantined', 'unjudged', 'mine', 'later', 'open-ended'])
			->willReturn(
				[
					'live'        => $this->copy('live', 'bob'),
					'ended'       => $this->copy('ended', 'bob', new DateTime('-1 minute')),
					'drifted'     => $this->copy('drifted', 'mallory'),
					'quarantined' => $this->copy('quarantined', 'bob'),
					'unjudged'    => $this->copy('unjudged', 'carol'),
					'mine'        => $this->copy('mine', 'me'),
					'later'       => $this->copy('later', 'dave', new DateTime('+1 day')),
					'open-ended'  => $this->copy('open-ended', 'erin'),
				]
			);
		$this->rowJudge->expects($this->never())->method('rowsOfSource');

		$result = $this->guards->liveCopies($this->source(), 'mine', $judged);

		$this->assertSame(
			[
				'copies'   => [
					'live'       => ['secretId' => 'live', 'holder' => 'recipient', 'targetUserId' => 'bob'],
					'later'      => ['secretId' => 'later', 'holder' => 'recipient', 'targetUserId' => 'dave'],
					'open-ended' => ['secretId' => 'open-ended', 'holder' => 'recipient', 'targetUserId' => 'erin'],
				],
				'ignored'  => ['ended' => true, 'gone' => true, 'drifted' => true, 'quarantined' => true, 'unjudged' => true],
				'unjudged' => ['unjudged'],
			],
			$result
		);
	}//end testSortsEveryKindOfCopy()

	/**
	 * Without judged rows the source's rows are judged here, and a copy whose
	 * access ended a moment ago is ignored.
	 *
	 * @return void
	 */
	public function testJudgesTheRowsWhenNoneAreGiven(): void {
		$targets = [$this->target(1, 'past', 'bob')];
		$this->rowJudge->expects($this->once())->method('rowsOfSource')->with('src')
			->willReturn(new JudgedShareRows($targets, static fn (): ?string => null));
		$this->secrets->method('findByIds')->willReturn(['past' => $this->copy('past', 'bob', new DateTime('-1 second'))]);

		$this->assertSame(
			['copies' => [], 'ignored' => ['past' => true], 'unjudged' => []],
			$this->guards->liveCopies($this->source(), null)
		);
	}//end testJudgesTheRowsWhenNoneAreGiven()
}//end class
