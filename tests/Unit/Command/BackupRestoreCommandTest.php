<?php

/**
 * Unit tests for keepiq:backup:restore's argument and refusal paths.
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

use InvalidArgumentException;
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Backup\RestoreService;
use OCA\Keepiq\Command\BackupRestore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BackupRestore: an archive that cannot be opened, and the key file handed through.
 *
 * @covers \OCA\Keepiq\Command\BackupRestore
 */
class BackupRestoreCommandTest extends TestCase {

	/**
	 * An archive that cannot be opened prints the reason, exits 1 and restores nothing.
	 *
	 * @return void
	 */
	public function testAnUnopenableArchiveExitsOne(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('localCopy')->with('missing.zip')->willReturn('/tmp/missing.zip');
		$restore = $this->createMock(RestoreService::class);
		$restore->expects($this->once())->method('open')->with('/tmp/missing.zip', null)
			->willThrowException(new InvalidArgumentException('No such archive'));
		$restore->expects($this->never())->method('refusals');
		$restore->expects($this->never())->method('restore');

		$tester = new CommandTester(new BackupRestore(backups: $backups, restore: $restore));

		$this->assertSame(1, $tester->execute(['file' => 'missing.zip']));
		$this->assertSame("No such archive\n", $tester->getDisplay());
	}//end testAnUnopenableArchiveExitsOne()

	/**
	 * The key file option is passed to open() as a string.
	 *
	 * @return void
	 */
	public function testTheKeyFileIsPassedToOpen(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('localCopy')->willReturn('/tmp/a.zip.enc');
		$restore = $this->createMock(RestoreService::class);
		$restore->expects($this->once())->method('open')->with('/tmp/a.zip.enc', '/keys/backup.pem')
			->willThrowException(new InvalidArgumentException('Wrong key'));

		$tester = new CommandTester(new BackupRestore(backups: $backups, restore: $restore));

		$this->assertSame(1, $tester->execute(['file' => 'a.zip.enc', '--key-file' => '/keys/backup.pem']));
		$this->assertSame("Wrong key\n", $tester->getDisplay());
	}//end testTheKeyFileIsPassedToOpen()
}//end class
