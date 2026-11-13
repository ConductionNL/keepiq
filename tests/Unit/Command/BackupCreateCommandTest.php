<?php

/**
 * Unit tests for the keepiq:backup:create command.
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
use OCA\Keepiq\Command\BackupCreate;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BackupCreate reports the written archive or the failure.
 *
 * @covers \OCA\Keepiq\Command\BackupCreate
 */
class BackupCreateCommandTest extends TestCase {

	/**
	 * The command's name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$this->assertSame('keepiq:backup:create', (new BackupCreate($this->createMock(BackupService::class)))->getName());
	}//end testName()

	/**
	 * An encrypted archive is reported with its size; exit 0.
	 *
	 * @return void
	 */
	public function testReportsAnEncryptedArchive(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->expects($this->once())->method('createBackup')->willReturn(['name' => 'keepiq-1.zip.enc', 'size' => 2048, 'encrypted' => true]);
		$tester = new CommandTester(new BackupCreate($backups));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("Wrote keepiq-1.zip.enc (2048 bytes, encrypted)\n", $tester->getDisplay());
	}//end testReportsAnEncryptedArchive()

	/**
	 * A plain archive says it is not encrypted.
	 *
	 * @return void
	 */
	public function testReportsAPlainArchive(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('createBackup')->willReturn(['name' => 'keepiq-2.zip', 'size' => 10, 'encrypted' => false]);
		$tester = new CommandTester(new BackupCreate($backups));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("Wrote keepiq-2.zip (10 bytes, not encrypted)\n", $tester->getDisplay());
	}//end testReportsAPlainArchive()

	/**
	 * A failing backup prints the reason and exits 1.
	 *
	 * @return void
	 */
	public function testAFailureExitsOne(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('createBackup')->willThrowException(new RuntimeException('disk full'));
		$tester = new CommandTester(new BackupCreate($backups));

		$this->assertSame(1, $tester->execute([]));
		$this->assertSame("Backup failed: disk full\n", $tester->getDisplay());
	}//end testAFailureExitsOne()
}//end class
