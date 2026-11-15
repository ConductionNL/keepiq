<?php

/**
 * Unit tests for keepiq:backup:verify.
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
use OCA\Keepiq\Command\BackupVerify;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BackupVerify: the verified archive's summary, the key file, and an archive that fails.
 *
 * @covers \OCA\Keepiq\Command\BackupVerify
 */
class BackupVerifyCommandTest extends TestCase {

	/**
	 * The command is registered under its occ name with the file argument and key option.
	 *
	 * @return void
	 */
	public function testTheCommandIsNamedAndTakesAFileAndAKey(): void {
		$command = new BackupVerify(backups: $this->createMock(BackupService::class), restore: $this->createMock(RestoreService::class));

		$this->assertSame('keepiq:backup:verify', $command->getName());
		$this->assertTrue($command->getDefinition()->getArgument('file')->isRequired());
		$this->assertTrue($command->getDefinition()->getOption('key-file')->isValueRequired());
	}//end testTheCommandIsNamedAndTakesAFileAndAKey()

	/**
	 * A verified archive prints when and by which version it was written and how many files were checked.
	 *
	 * @return void
	 */
	public function testAVerifiedArchiveIsSummarised(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->expects($this->once())->method('localCopy')->with('keepiq-2026-11-01.zip')->willReturn('/tmp/local.zip');
		$restore = $this->createMock(RestoreService::class);
		$restore->expects($this->once())->method('open')->with('/tmp/local.zip', null)->willReturn([
			'zip'      => '/tmp/work.zip',
			'manifest' => ['createdAt' => '2026-11-01T10:00:00Z', 'appVersion' => '1.4.0', 'files' => ['a' => 'x', 'b' => 'y', 'c' => 'z']],
		]);

		$tester = new CommandTester(new BackupVerify(backups: $backups, restore: $restore));

		$this->assertSame(0, $tester->execute(['file' => 'keepiq-2026-11-01.zip']));
		$this->assertSame(
			"The archive is complete and unchanged.\nWritten: 2026-11-01T10:00:00Z, Keepiq 1.4.0\nFiles checked: 3\n",
			$tester->getDisplay()
		);
	}//end testAVerifiedArchiveIsSummarised()

	/**
	 * A manifest without those fields still prints, with blanks and zero files.
	 *
	 * @return void
	 */
	public function testAnEmptyManifestPrintsBlanks(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('localCopy')->willReturn('/tmp/local.zip.enc');
		$restore = $this->createMock(RestoreService::class);
		$restore->expects($this->once())->method('open')->with('/tmp/local.zip.enc', '/keys/backup.pem')
			->willReturn(['zip' => '/tmp/work.zip', 'manifest' => []]);

		$tester = new CommandTester(new BackupVerify(backups: $backups, restore: $restore));

		$this->assertSame(0, $tester->execute(['file' => 'a.zip.enc', '--key-file' => '/keys/backup.pem']));
		$this->assertSame("The archive is complete and unchanged.\nWritten: , Keepiq \nFiles checked: 0\n", $tester->getDisplay());
	}//end testAnEmptyManifestPrintsBlanks()

	/**
	 * An archive that fails to open or verify prints the reason and exits 1.
	 *
	 * @return void
	 */
	public function testAFailedArchiveExitsOne(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('localCopy')->willReturn('/tmp/local.zip');
		$restore = $this->createMock(RestoreService::class);
		$restore->method('open')->willThrowException(new InvalidArgumentException('Checksum mismatch for vaults.json'));

		$tester = new CommandTester(new BackupVerify(backups: $backups, restore: $restore));

		$this->assertSame(1, $tester->execute(['file' => 'a.zip']));
		$this->assertSame("Checksum mismatch for vaults.json\n", $tester->getDisplay());
	}//end testAFailedArchiveExitsOne()
}//end class
