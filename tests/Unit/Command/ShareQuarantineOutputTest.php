<?php

/**
 * Unit tests for keepiq:share-quarantine argument handling and output.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Command
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

namespace OCA\Keepiq\Tests\Unit\Command;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Command\ShareQuarantine;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\ShareQuarantineService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ShareQuarantine over a mocked quarantine service: usage, release, drop and the list table.
 *
 * @covers \OCA\Keepiq\Command\ShareQuarantine
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ShareQuarantineOutputTest extends TestCase {

	/** @var ShareQuarantineService&MockObject */
	private ShareQuarantineService $service;

	/**
	 * Set up the service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(ShareQuarantineService::class);
	}//end setUp()

	/**
	 * A tester over the command.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		return new CommandTester(new ShareQuarantine($this->service));
	}//end tester()

	/**
	 * An unknown action, or release/drop without an id, prints usage and exits 1.
	 *
	 * @return void
	 */
	public function testUsageOnBadArguments(): void {
		$this->service->expects($this->never())->method('release');
		$this->service->expects($this->never())->method('drop');
		foreach ([['action' => 'purge', 'id' => 'r1'], ['action' => 'release'], ['action' => 'drop', 'id' => '']] as $args) {
			$tester = $this->tester();
			$this->assertSame(1, $tester->execute($args), json_encode($args));
			$this->assertStringStartsWith('Use: keepiq:share-quarantine list | release <id>', $tester->getDisplay());
		}
	}//end testUsageOnBadArguments()

	/**
	 * Release passes the force flag and confirms.
	 *
	 * @return void
	 */
	public function testReleasePassesForce(): void {
		$this->service->expects($this->exactly(2))->method('release')
			->willReturnCallback(function (string $id, bool $force): ShareTarget {
				static $calls = 0;
				$this->assertSame(['r1', $calls === 0 ? false : true], [$id, $force]);
				$calls++;
				return new ShareTarget();
			});

		$tester = $this->tester();
		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => 'r1']));
		$this->assertSame("Released share row r1: it is a live share again.\n", $tester->getDisplay());
		$this->assertSame(0, $this->tester()->execute(['action' => 'release', 'id' => 'r1', '--force' => true]));
	}//end testReleasePassesForce()

	/**
	 * A refused release prints the reason and exits 1.
	 *
	 * @return void
	 */
	public function testARefusedReleaseExitsOne(): void {
		$this->service->method('release')->willThrowException(new InvalidArgumentException('Copy is held by a third party'));
		$tester = $this->tester();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => 'r1']));
		$this->assertSame("Copy is held by a third party\n", $tester->getDisplay());
	}//end testARefusedReleaseExitsOne()

	/**
	 * Drop reports whether the copy's restrictions were cleared, kept, or not asked about.
	 *
	 * @return void
	 */
	public function testDropReportsTheRestrictionsOutcome(): void {
		$this->service->expects($this->exactly(3))->method('drop')
			->willReturnCallback(static fn (string $id, bool $clearRestrictions): bool => ($id === 'cleared'));

		$tester = $this->tester();
		$this->assertSame(0, $tester->execute(['action' => 'drop', 'id' => 'cleared', '--clear-restrictions' => true]));
		$this->assertSame("Dropped share row cleared; its copy was kept.\nThe copy's use-only flag and access end date were cleared.\n", $tester->getDisplay());

		$tester = $this->tester();
		$tester->execute(['action' => 'drop', 'id' => 'kept', '--clear-restrictions' => true]);
		$this->assertSame("Dropped share row kept; its copy was kept.\nThe copy's restrictions were left as they are: the row's recipient does not hold it.\n", $tester->getDisplay());

		$tester = $this->tester();
		$tester->execute(['action' => 'drop', 'id' => 'plain']);
		$this->assertSame("Dropped share row plain; its copy was kept.\n", $tester->getDisplay());
	}//end testDropReportsTheRestrictionsOutcome()

	/**
	 * A share row with the given copy.
	 *
	 * @param string $id Row id
	 * @param string $copyId The copy's secret id
	 *
	 * @return ShareTarget
	 */
	private static function row(string $id, string $copyId): ShareTarget {
		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId('src-' . $id);
		$row->setTargetUserId('bob');
		$row->setSecretId($copyId);
		$row->setCreatedBy('alice');
		$row->setCreatedAt(new DateTime('2026-03-01 10:00:00'));
		$row->setQuarantinedAt(new DateTime('2026-04-01 11:00:00'));
		$row->setQuarantineReason('pre_existing_copy');
		return $row;
	}//end row()

	/**
	 * A copy secret.
	 *
	 * @param string $id Secret id
	 * @param string $ownerType Owner type
	 * @param string $ownerId Owner id
	 *
	 * @return Secret
	 */
	private static function copy(string $id, string $ownerType, string $ownerId): Secret {
		$copy = new Secret();
		$copy->setId($id);
		$copy->setOwnerType($ownerType);
		$copy->setOwnerId($ownerId);
		return $copy;
	}//end copy()

	/**
	 * The list shows who holds each copy and every restriction on it.
	 *
	 * @return void
	 */
	public function testListShowsHolderAndRestrictions(): void {
		$held = self::copy(id: 'c1', ownerType: 'user', ownerId: 'bob');
		$held->setUseOnly(true);
		$held->setAccessExpiresAt(new DateTime('2026-12-31 23:00:00'));
		$heldRow = self::row(id: 'r1', copyId: 'c1');
		$heldRow->setExpiresAt(new DateTime('2026-06-30 08:00:00'));

		$other = self::copy(id: 'c2', ownerType: 'group', ownerId: 'ops');

		$this->service->expects($this->once())->method('list')->willReturn([
			['row' => $heldRow, 'copy' => $held],
			['row' => self::row(id: 'r2', copyId: 'c2'), 'copy' => $other],
			['row' => self::row(id: 'r3', copyId: 'c3'), 'copy' => null],
		]);
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['action' => 'list']));
		$cells = [];
		foreach (explode("\n", $tester->getDisplay()) as $line) {
			if (str_starts_with($line, '| r') === true) {
				$cells[] = array_map('trim', array_slice(explode('|', rtrim($line)), 1, -1));
			}
		}

		$this->assertSame(
			['r1', 'src-r1', 'bob', 'c1', 'alice', '2026-03-01 10:00:00', '2026-04-01 11:00:00', 'pre_existing_copy', 'recipient', 'copy use-only, copy access ends 2026-12-31 23:00:00, share ends 2026-06-30 08:00:00'],
			$cells[0]
		);
		$this->assertSame(['group ops', ''], array_slice($cells[1], 8));
		$this->assertSame(['', 'copy gone'], array_slice($cells[2], 8));
	}//end testListShowsHolderAndRestrictions()
}//end class
