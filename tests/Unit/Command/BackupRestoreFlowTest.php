<?php

/**
 * Unit tests for keepiq:backup:restore after the archive opens.
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
use OCA\Keepiq\Backup\RestoreService;
use OCA\Keepiq\Command\BackupRestore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BackupRestore: refusals, the dry run and its age notice, the confirmation,
 * and the restore with its success and failure output.
 *
 * @covers \OCA\Keepiq\Command\BackupRestore
 */
class BackupRestoreFlowTest extends TestCase {

	private const MANIFEST = ['createdAt' => '2026-11-01T10:00:00Z', 'files' => ['a', 'b']];

	/**
	 * The restore service the command drives.
	 *
	 * @var RestoreService&MockObject
	 */
	private RestoreService $restore;

	/**
	 * The backup service that finds the archive.
	 *
	 * @var BackupService&MockObject
	 */
	private BackupService $backups;

	/**
	 * An archive that opens as /tmp/work/a.zip with the manifest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->backups = $this->createMock(BackupService::class);
		$this->backups->method('localCopy')->willReturn('/tmp/local/a.zip');
		$this->restore = $this->createMock(RestoreService::class);
		$this->restore->method('open')->willReturn(['zip' => '/tmp/work/a.zip', 'manifest' => self::MANIFEST]);
	}//end setUp()

	/**
	 * A command tester whose command can ask questions.
	 *
	 * @param HelperSet|null $helpers The helpers, a question helper by default.
	 *
	 * @return CommandTester
	 */
	private function tester(?HelperSet $helpers = null): CommandTester {
		$command = new BackupRestore(backups: $this->backups, restore: $this->restore);
		$command->setHelperSet($helpers ?? new HelperSet(['question' => new QuestionHelper()]));

		return new CommandTester($command);
	}//end tester()

	/**
	 * Every refusal is printed, the command exits 1 and neither counts nor restores.
	 *
	 * @return void
	 */
	public function testRefusalsArePrintedAndStopTheRestore(): void {
		$this->restore->expects($this->once())->method('refusals')->with(self::MANIFEST, false)
			->willReturn(['The archive is older than the newest audit entry.', 'Another app version.']);
		$this->restore->expects($this->never())->method('compare');
		$this->restore->expects($this->never())->method('restore');

		$tester = $this->tester();

		$this->assertSame(1, $tester->execute(['file' => 'a.zip']));
		$this->assertSame("The archive is older than the newest audit entry.\nAnother app version.\n", $tester->getDisplay());
	}//end testRefusalsArePrintedAndStopTheRestore()

	/**
	 * A forced restore asks for the refusals with force on.
	 *
	 * @return void
	 */
	public function testForceIsPassedToTheRefusals(): void {
		$this->restore->expects($this->once())->method('refusals')->with(self::MANIFEST, true)->willReturn(['Broken checksum.']);

		$this->assertSame(1, $this->tester()->execute(['file' => 'a.zip', '--force' => true]));
	}//end testForceIsPassedToTheRefusals()

	/**
	 * A dry run ignores the age rule, shows the counts, notes the age and changes nothing.
	 *
	 * @return void
	 */
	public function testADryRunShowsTheCountsAndTheAgeNotice(): void {
		$this->restore->expects($this->once())->method('refusals')->with(self::MANIFEST, true)->willReturn([]);
		$this->restore->method('compare')->with(self::MANIFEST)
			->willReturn(['keepiq_vaults' => ['current' => 3, 'archive' => 5], 'keepiq_items' => ['current' => 40, 'archive' => 38]]);
		$this->restore->expects($this->once())->method('isOlderThanNewestAuditEntry')->with(self::MANIFEST)->willReturn(true);
		$this->restore->expects($this->never())->method('warnings');
		$this->restore->expects($this->never())->method('restore');

		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['file' => 'a.zip', '--dry-run' => true]));
		$display = $tester->getDisplay();
		$this->assertMatchesRegularExpression('/\|\s*Table\s*\|\s*Rows now\s*\|\s*Rows in archive\s*\|/', $display);
		$this->assertMatchesRegularExpression('/\|\s*keepiq_vaults\s*\|\s*3\s*\|\s*5\s*\|/', $display);
		$this->assertMatchesRegularExpression('/\|\s*keepiq_items\s*\|\s*40\s*\|\s*38\s*\|/', $display);
		$this->assertStringContainsString('The archive is older than the newest audit entry. A real restore needs --force to roll back on purpose.', $display);
		$this->assertStringEndsWith("Dry run: nothing changed.\n", $display);
	}//end testADryRunShowsTheCountsAndTheAgeNotice()

	/**
	 * A dry run of an archive that is not older has no notice.
	 *
	 * @return void
	 */
	public function testADryRunOfACurrentArchiveHasNoNotice(): void {
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->expects($this->once())->method('isOlderThanNewestAuditEntry')->willReturn(false);

		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['file' => 'a.zip', '--dry-run' => true]));
		$this->assertStringNotContainsString('older than the newest audit entry', $tester->getDisplay());
		$this->assertStringEndsWith("Dry run: nothing changed.\n", $tester->getDisplay());
	}//end testADryRunOfACurrentArchiveHasNoNotice()

	/**
	 * A forced dry run does not look at the archive's age.
	 *
	 * @return void
	 */
	public function testAForcedDryRunSkipsTheAgeCheck(): void {
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->expects($this->never())->method('isOlderThanNewestAuditEntry');

		$this->assertSame(0, $this->tester()->execute(['file' => 'a.zip', '--dry-run' => true, '--force' => true]));
	}//end testAForcedDryRunSkipsTheAgeCheck()

	/**
	 * Answering no prints the warnings, cancels and restores nothing.
	 *
	 * @return void
	 */
	public function testDecliningCancelsTheRestore(): void {
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->expects($this->once())->method('warnings')->with('/tmp/work/a.zip', self::MANIFEST)
			->willReturn(['Users need their own key afterwards.']);
		$this->restore->expects($this->never())->method('restore');

		$tester = $this->tester();
		$tester->setInputs(['n']);

		$this->assertSame(1, $tester->execute(['file' => 'a.zip']));
		$display = $tester->getDisplay();
		$this->assertStringContainsString("Users need their own key afterwards.\n", $display);
		$this->assertStringContainsString('Replace every Keepiq vault with this backup? [y/N] ', $display);
		$this->assertStringEndsWith("Restore cancelled. Nothing changed.\n", $display);
	}//end testDecliningCancelsTheRestore()

	/**
	 * Pressing enter takes the default, which is no.
	 *
	 * @return void
	 */
	public function testTheDefaultAnswerIsNo(): void {
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->method('warnings')->willReturn([]);
		$this->restore->expects($this->never())->method('restore');

		$tester = $this->tester();
		$tester->setInputs(['']);

		$this->assertSame(1, $tester->execute(['file' => 'a.zip']));
		$this->assertStringEndsWith("Restore cancelled. Nothing changed.\n", $tester->getDisplay());
	}//end testTheDefaultAnswerIsNo()

	/**
	 * Without a question helper the command cannot confirm, so it cancels.
	 *
	 * @return void
	 */
	public function testWithoutAQuestionHelperItCancels(): void {
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->method('warnings')->willReturn([]);
		$this->restore->expects($this->never())->method('restore');

		$tester = $this->tester(new HelperSet(['question' => new FormatterHelper()]));

		$this->assertSame(1, $tester->execute(['file' => 'a.zip']));
		$this->assertStringEndsWith("Restore cancelled. Nothing changed.\n", $tester->getDisplay());
	}//end testWithoutAQuestionHelperItCancels()

	/**
	 * Answering yes restores the opened archive under its base name and reports the counts.
	 *
	 * @return void
	 */
	public function testConfirmingRestoresAndReportsTheCounts(): void {
		$this->backups->expects($this->once())->method('localCopy')->with('/backups/keepiq/a.zip');
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->method('warnings')->willReturn([]);
		$this->restore->expects($this->once())->method('restore')->with('/tmp/work/a.zip', self::MANIFEST, 'a.zip')
			->willReturn(['rows' => 43, 'blobs' => 7]);

		$tester = $this->tester();
		$tester->setInputs(['y']);

		$this->assertSame(0, $tester->execute(['file' => '/backups/keepiq/a.zip']));
		$this->assertStringEndsWith(
			"Switching maintenance mode on for the restore.\nMaintenance mode is off again.\nRestored 43 rows and 7 attachment blobs.\n",
			$tester->getDisplay()
		);
	}//end testConfirmingRestoresAndReportsTheCounts()

	/**
	 * A failing restore names the error, says maintenance mode is off and exits 1.
	 *
	 * @return void
	 */
	public function testAFailedRestoreReportsTheError(): void {
		$this->restore->method('refusals')->willReturn([]);
		$this->restore->method('compare')->willReturn([]);
		$this->restore->method('warnings')->willReturn([]);
		$this->restore->method('restore')->willThrowException(new RuntimeException('disk full'));

		$tester = $this->tester();
		$tester->setInputs(['yes']);

		$this->assertSame(1, $tester->execute(['file' => 'a.zip']));
		$this->assertStringEndsWith(
			"Switching maintenance mode on for the restore.\nRestore failed: disk full\nMaintenance mode is off again.\n",
			$tester->getDisplay()
		);
	}//end testAFailedRestoreReportsTheError()
}//end class
