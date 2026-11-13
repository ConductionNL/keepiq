<?php

/**
 * Unit tests for the keepiq:backup:list command.
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

use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Command\BackupList;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BackupList renders one table row per archive.
 *
 * @covers \OCA\Keepiq\Command\BackupList
 */
class BackupListCommandTest extends TestCase {

	/**
	 * Each archive is a row with its size, time, encryption and path.
	 *
	 * @return void
	 */
	public function testRendersEachArchive(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->expects($this->once())->method('listArchives')->willReturn([
			['name' => 'a.zip.enc', 'size' => 100, 'createdAt' => 1700000000, 'encrypted' => true, 'path' => '/data/a.zip.enc'],
			['name' => 'b.zip', 'size' => 7, 'createdAt' => 1700003600, 'encrypted' => false, 'path' => '/data/b.zip'],
		]);
		$tester = new CommandTester(new BackupList($backups));

		$this->assertSame(0, $tester->execute([]));
		$lines = array_map('trim', explode("\n", $tester->getDisplay()));
		$this->assertMatchesRegularExpression('/^\| Name\s+\| Bytes \| Time\s+\| Encrypted \| Path\s+\|$/', $lines[1]);
		$this->assertMatchesRegularExpression('/^\| a\.zip\.enc \| 100\s+\| ' . preg_quote(date('Y-m-d H:i:s', 1700000000), '/') . ' \| yes\s+\| \/data\/a\.zip\.enc \|$/', $lines[3]);
		$this->assertMatchesRegularExpression('/^\| b\.zip\s+\| 7\s+\| ' . preg_quote(date('Y-m-d H:i:s', 1700003600), '/') . ' \| no\s+\| \/data\/b\.zip\s+\|$/', $lines[4]);
	}//end testRendersEachArchive()

	/**
	 * No archives is a header-only table, exit 0.
	 *
	 * @return void
	 */
	public function testNoArchivesIsAnEmptyTable(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('listArchives')->willReturn([]);
		$tester = new CommandTester(new BackupList($backups));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(3, substr_count(trim($tester->getDisplay()), "\n") + 1, "border, header, border only");
		$this->assertSame('keepiq:backup:list', (new BackupList($backups))->getName());
	}//end testNoArchivesIsAnEmptyTable()
}//end class
