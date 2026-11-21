<?php

/**
 * Unit tests for the edges of the attachment blob relocation step: an empty or
 * unlistable source, a missing or unopenable target, the streamed copy, and
 * what is said about the old namespace afterwards.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Repair\MoveAttachmentBlobs;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The edge paths of MoveAttachmentBlobs.
 *
 * @covers \OCA\Keepiq\Repair\MoveAttachmentBlobs
 * @uses   \OCA\Keepiq\Repair\BlobRelocation
 * @uses   \OCA\Keepiq\Repair\SourceBlob
 */
final class MoveAttachmentBlobsEdgeTest extends TestCase {

	/**
	 * Every info and warning, in order.
	 *
	 * @var array<int,array{string,string}>
	 */
	private array $said = [];

	private IAppData&MockObject $old;
	private IAppData&MockObject $new;

	/**
	 * Fresh AppData roots per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->said = [];
		$this->old  = $this->createMock(IAppData::class);
		$this->new  = $this->createMock(IAppData::class);
	}//end setUp()

	/**
	 * An output recording info and warning lines.
	 *
	 * @return IOutput
	 */
	private function recorder(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $m): void {
			$this->said[] = ['info', $m];
		});
		$output->method('warning')->willReturnCallback(function (string $m): void {
			$this->said[] = ['warning', $m];
		});

		return $output;
	}//end output()

	/**
	 * The step over a factory answering the two roots.
	 *
	 * @return MoveAttachmentBlobs
	 */
	private function step(): MoveAttachmentBlobs {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturnCallback(
			fn (string $ns): IAppData => ($ns === 'doriath') ? $this->old : $this->new
		);

		return new MoveAttachmentBlobs($factory);
	}//end step()

	/**
	 * A blob of the given name and size whose read() answers the given handle.
	 *
	 * @param string $name   The name.
	 * @param int    $size   The size.
	 * @param mixed  $handle What read() answers.
	 *
	 * @return ISimpleFile&MockObject
	 */
	private function blob(string $name, int $size, mixed $handle = false): ISimpleFile&MockObject {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		$file->method('read')->willReturn($handle);
		$file->method('getContent')->willReturn(str_repeat('x', $size));

		return $file;
	}//end blob()

	/**
	 * A written file reporting the given size.
	 *
	 * @param int $size The size.
	 *
	 * @return ISimpleFile
	 */
	private function written(int $size): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getSize')->willReturn($size);

		return $file;
	}//end written()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testItNamesTheMove(): void {
		self::assertSame('Keepiq: move attachment blobs to the keepiq AppData namespace', $this->step()->getName());
	}//end testItNamesTheMove()

	/**
	 * An empty old folder is removed and nothing is opened on the target side.
	 *
	 * @return void
	 */
	public function testAnEmptySourceIsRemovedWithoutOpeningTheTarget(): void {
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturn([]);
		$source->expects($this->once())->method('delete');
		$this->old->method('getFolder')->with('attachments')->willReturn($source);
		$this->new->expects($this->never())->method('getFolder');
		$this->new->expects($this->never())->method('newFolder');

		$this->step()->run($this->recorder());

		self::assertSame([['info', 'The old blob folder is already empty - nothing to move.']], $this->said);
	}//end testAnEmptySourceIsRemovedWithoutOpeningTheTarget()

	/**
	 * An unlistable old folder is reported and treated as empty, and its failed removal reported too.
	 *
	 * @return void
	 */
	public function testAnUnlistableSourceIsReportedAndLeftInPlace(): void {
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willThrowException(new RuntimeException('io error'));
		$source->expects($this->never())->method('delete');
		$this->old->method('getFolder')->willReturn($source);

		$this->step()->run($this->recorder());

		self::assertSame(
			[
				['warning', 'Could not list the old blob folder: io error'],
				['warning', 'Could not remove the emptied blob folder: io error'],
				['info', 'The old blob folder is already empty - nothing to move.'],
			],
			$this->said
		);
	}//end testAnUnlistableSourceIsReportedAndLeftInPlace()

	/**
	 * A target folder that does not exist yet is created and receives the blob.
	 *
	 * @return void
	 */
	public function testAMissingTargetFolderIsCreated(): void {
		$file = $this->blob('b.bin', 3);
		$file->expects($this->once())->method('delete');
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$file], []);
		$this->old->method('getFolder')->willReturn($source);
		$this->old->method('getDirectoryListing')->willReturn([]);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->expects($this->once())->method('newFile')->with('b.bin', 'xxx')->willReturn($this->written(3));
		$this->new->method('getFolder')->with('attachments')->willThrowException(new NotFoundException());
		$this->new->expects($this->once())->method('newFolder')->with('attachments')->willReturn($target);

		$this->step()->run($this->recorder());

		self::assertContains(['info', 'Moved 1 attachment blob(s), skipped 0.'], $this->said);
	}//end testAMissingTargetFolderIsCreated()

	/**
	 * An unopenable target leaves every blob in place and says why.
	 *
	 * @return void
	 */
	public function testAnUnopenableTargetLeavesEveryBlobInPlace(): void {
		$file = $this->blob('b.bin', 3);
		$file->expects($this->never())->method('delete');
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturn([$file]);
		$source->expects($this->never())->method('delete');
		$this->old->method('getFolder')->willReturn($source);
		$this->new->method('getFolder')->willThrowException(new NotFoundException());
		$this->new->method('newFolder')->willThrowException(new RuntimeException('read-only'));

		$this->step()->run($this->recorder());

		self::assertSame(
			[['warning', 'Could not open the target blob folder, leaving every blob in place: read-only']],
			$this->said
		);
	}//end testAnUnopenableTargetLeavesEveryBlobInPlace()

	/**
	 * A readable handle is streamed into the new file and closed afterwards.
	 *
	 * @return void
	 */
	public function testAReadableHandleIsStreamedAndClosed(): void {
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, 'abcd');
		rewind($handle);
		$file = $this->blob('s.bin', 4, $handle);
		$file->expects($this->never())->method('getContent');
		$file->expects($this->once())->method('delete');
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$file], []);
		$this->old->method('getFolder')->willReturn($source);
		$this->old->method('getDirectoryListing')->willReturn([]);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->expects($this->once())->method('newFile')->with('s.bin', $handle)->willReturn($this->written(4));
		$this->new->method('getFolder')->willReturn($target);

		$this->step()->run($this->recorder());

		self::assertFalse(is_resource($handle), 'the source handle is closed after the copy');
		self::assertContains(['info', 'Moved 1 attachment blob(s), skipped 0.'], $this->said);
	}//end testAReadableHandleIsStreamedAndClosed()

	/**
	 * A streamed copy that throws still closes the handle and keeps the source.
	 *
	 * @return void
	 */
	public function testAFailingStreamedCopyClosesTheHandleAndKeepsTheSource(): void {
		$handle = fopen('php://memory', 'r');
		$file   = $this->blob('s.bin', 4, $handle);
		$file->expects($this->never())->method('delete');
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturn([$file]);
		$source->expects($this->never())->method('delete');
		$this->old->method('getFolder')->willReturn($source);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->method('newFile')->willThrowException(new RuntimeException('quota'));
		$this->new->method('getFolder')->willReturn($target);

		$this->step()->run($this->recorder());

		self::assertFalse(is_resource($handle));
		self::assertSame(
			[
				['warning', 'Could not move blob "s.bin": quota'],
				['info', 'Moved 0 attachment blob(s), skipped 1.'],
			],
			$this->said
		);
	}//end testAFailingStreamedCopyClosesTheHandleAndKeepsTheSource()

	/**
	 * A source that is not empty after the move is not removed, and nothing more is said.
	 *
	 * @return void
	 */
	public function testASourceThatRefillsIsNotRemoved(): void {
		$file = $this->blob('b.bin', 3);
		$late = $this->blob('late.bin', 1);
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$file], [$late]);
		$source->expects($this->never())->method('delete');
		$this->old->method('getFolder')->willReturn($source);
		$this->old->method('getDirectoryListing')->willReturn(['attachments' => $source]);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->method('newFile')->willReturn($this->written(3));
		$this->new->method('getFolder')->willReturn($target);

		$this->step()->run($this->recorder());

		self::assertSame([['info', 'Moved 1 attachment blob(s), skipped 0.']], $this->said);
	}//end testASourceThatRefillsIsNotRemoved()

	/**
	 * Once the old namespace is empty the leftover directory is reported.
	 *
	 * @return void
	 */
	public function testAnEmptyOldNamespaceIsReportedAfterTheMove(): void {
		$file   = $this->blob('b.bin', 3);
		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$file], []);
		$source->expects($this->once())->method('delete');
		$this->old->method('getFolder')->willReturn($source);
		$this->old->method('getDirectoryListing')->willReturn([]);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->method('newFile')->willReturn($this->written(3));
		$this->new->method('getFolder')->willReturn($target);

		$this->step()->run($this->recorder());

		self::assertSame(
			[
				['info', 'Moved 1 attachment blob(s), skipped 0.'],
				[
					'info',
					'The empty "doriath" AppData directory remains; ISimpleRoot exposes no delete() so it '
					. 'must be removed by hand if you want it gone. Nothing reads it.',
				],
			],
			$this->said
		);
	}//end testAnEmptyOldNamespaceIsReportedAfterTheMove()

	/**
	 * Without an old folder, an empty old namespace is still reported, and an unreadable one is not.
	 *
	 * @return void
	 */
	public function testWithoutAnOldFolderOnlyAnEmptyNamespaceIsReported(): void {
		$this->old->method('getFolder')->willThrowException(new NotFoundException());
		$this->old->method('getDirectoryListing')->willReturnOnConsecutiveCalls(
			[],
			$this->throwException(new RuntimeException('gone'))
		);

		$this->step()->run($this->recorder());
		self::assertCount(2, $this->said);
		self::assertSame(['info', 'No blobs under the old AppData namespace - nothing to move.'], $this->said[0]);
		self::assertStringStartsWith('The empty "doriath" AppData directory remains', $this->said[1][1]);

		$this->said = [];
		$this->step()->run($this->recorder());
		self::assertSame([['info', 'No blobs under the old AppData namespace - nothing to move.']], $this->said);
	}//end testWithoutAnOldFolderOnlyAnEmptyNamespaceIsReported()
}//end class
