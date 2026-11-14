<?php

/**
 * Unit tests for LiveCopiesRun.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareSync\LiveCopiesRun;
use PHPUnit\Framework\TestCase;

/**
 * Tests for sorting a source's share rows into live, ignored and unjudged copies.
 *
 * @covers \OCA\Keepiq\Service\ShareSync\LiveCopiesRun
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Service\JudgedShareRows
 */
class LiveCopiesRunTest extends TestCase {
	private function target(int $id, string $copyId, string $userId): ShareTarget {
		$target = new ShareTarget();
		$target->setId($id);
		$target->setSecretId($copyId);
		$target->setTargetUserId($userId);

		return $target;
	}

	private function copy(string $copyId, string $userId, ?DateTime $ends = null): Secret {
		$copy = new Secret();
		$copy->setId($copyId);
		$copy->setOwnerType('user');
		$copy->setOwnerId($userId);
		$copy->setAccessExpiresAt($ends);

		return $copy;
	}

	/**
	 * @param array<int, string|null> $verdicts
	 * @param list<string>            $asked
	 */
	private function judged(array $verdicts, array &$asked = []): JudgedShareRows {
		return new JudgedShareRows(
			[],
			static function (ShareTarget $row) use ($verdicts, &$asked): ?string {
				$asked[] = $row->getId();
				return ($verdicts[$row->getId()] ?? null);
			}
		);
	}

	public function testAnEmptyRunHasNoCopies(): void {
		$run = new LiveCopiesRun($this->judged([]), []);

		$this->assertSame(['copies' => [], 'ignored' => [], 'unjudged' => []], $run->result());
	}

	public function testAWritableCopyIsLive(): void {
		$run = new LiveCopiesRun($this->judged([]), ['c1' => $this->copy('c1', 'bob', new DateTime('+1 hour'))]);

		$run->sort($this->target(1, 'c1', 'bob'));

		$this->assertSame(
			[
				'copies' => ['c1' => ['secretId' => 'c1', 'holder' => 'recipient', 'targetUserId' => 'bob']],
				'ignored' => [],
				'unjudged' => [],
			],
			$run->result()
		);
	}

	public function testAMissingEndedOrDriftedCopyIsIgnoredWithoutAskingTheScan(): void {
		$asked = [];
		$run   = new LiveCopiesRun(
			$this->judged([], $asked),
			[
				'ended' => $this->copy('ended', 'bob', new DateTime('-1 second')),
				'drifted' => $this->copy('drifted', 'mallory'),
			]
		);

		$run->sort($this->target(1, 'gone', 'bob'));
		$run->sort($this->target(2, 'ended', 'bob'));
		$run->sort($this->target(3, 'drifted', 'bob'));

		$this->assertSame([], $asked);
		$this->assertSame(
			['copies' => [], 'ignored' => ['gone' => true, 'ended' => true, 'drifted' => true], 'unjudged' => []],
			$run->result()
		);
	}

	public function testAQuarantinedCopyIsIgnoredButNotUnjudged(): void {
		$run = new LiveCopiesRun(
			$this->judged([1 => ShareQuarantineScanner::HELD_QUARANTINED]),
			['c1' => $this->copy('c1', 'bob')]
		);

		$run->sort($this->target(1, 'c1', 'bob'));

		$this->assertSame(['copies' => [], 'ignored' => ['c1' => true], 'unjudged' => []], $run->result());
	}

	public function testAnUnjudgedCopyIsIgnoredAndListed(): void {
		$asked = [];
		$run   = new LiveCopiesRun(
			$this->judged([2 => ShareQuarantineScanner::HELD_UNJUDGED], $asked),
			['c1' => $this->copy('c1', 'bob'), 'c2' => $this->copy('c2', 'carol')]
		);

		$run->sort($this->target(1, 'c1', 'bob'));
		$run->sort($this->target(2, 'c2', 'carol'));

		$this->assertSame(['1', '2'], $asked);
		$this->assertSame(
			[
				'copies' => ['c1' => ['secretId' => 'c1', 'holder' => 'recipient', 'targetUserId' => 'bob']],
				'ignored' => ['c2' => true],
				'unjudged' => ['c2'],
			],
			$run->result()
		);
	}
}
