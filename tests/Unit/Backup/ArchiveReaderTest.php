<?php

/**
 * Keepiq ArchiveReader Test
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Backup
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

namespace OCA\Keepiq\Tests\Unit\Backup;

use InvalidArgumentException;
use OCA\Keepiq\Backup\ArchiveReader;
use OCA\Keepiq\Backup\ArchiveWriter;
use OCA\Keepiq\Backup\BackupTableRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * What the reader refuses in a hand-built archive, and what it reads.
 */
#[CoversClass(ArchiveReader::class)]
class ArchiveReaderTest extends TestCase {

	/**
	 * The archive under test.
	 *
	 * @var string
	 */
	private string $zip;

	/**
	 * A scratch path per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->zip = sys_get_temp_dir() . '/keepiq-reader-test-' . bin2hex(random_bytes(4)) . '.zip';
	}//end setUp()

	/**
	 * Remove the archive.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (is_file($this->zip) === true) {
			unlink($this->zip);
		}
	}//end tearDown()

	/**
	 * Write an archive holding the given entries, plus a manifest over them.
	 *
	 * @param array<string,string> $entries   Entry name to content
	 * @param array<int,string>    $skipTable Tables to leave out of the manifest
	 * @param array<string,string> $extra     Manifest entries without a file
	 *
	 * @return void
	 */
	private function build(array $entries, array $skipTable = [], array $extra = []): void {
		$files = [];
		foreach (BackupTableRegistry::TABLES as $table) {
			$name = 'tables/' . $table . '.jsonl';
			$entries[$name] = ($entries[$name] ?? '');
		}

		foreach ($entries as $name => $content) {
			$table = substr((string)$name, 7, -6);
			if (in_array($table, $skipTable, true) === true) {
				continue;
			}

			$files[$name] = ['rows' => 0, 'sha256' => hash('sha256', $content)];
		}

		foreach ($extra as $name => $sha) {
			$files[$name] = ['rows' => 0, 'sha256' => $sha];
		}

		$archive = new ZipArchive();
		$archive->open($this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$archive->addFromString((string)$name, $content);
		}

		$archive->addFromString('manifest.json', (string)json_encode(['format' => ArchiveWriter::FORMAT, 'files' => $files]));
		$archive->close();
	}//end build()

	/**
	 * A file that is not a zip is refused as unreadable.
	 *
	 * @return void
	 */
	public function testAFileThatIsNoZipIsRefused(): void {
		file_put_contents($this->zip, 'not a zip at all');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Not a readable backup archive');
		(new ArchiveReader())->verify(zipPath: $this->zip);
	}//end testAFileThatIsNoZipIsRefused()

	/**
	 * A zip without a manifest, or with one of another format, is refused.
	 *
	 * @return void
	 */
	public function testAZipWithoutAValidManifestIsRefused(): void {
		$archive = new ZipArchive();
		$archive->open($this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$archive->addFromString('manifest.json', '{"format":"something-else"}');
		$archive->close();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Not a Keepiq vault backup (no valid manifest)');
		(new ArchiveReader())->verify(zipPath: $this->zip);
	}//end testAZipWithoutAValidManifestIsRefused()

	/**
	 * A manifest that leaves out a registered table is refused, naming it.
	 *
	 * @return void
	 */
	public function testAManifestMissingATableIsRefused(): void {
		$table = BackupTableRegistry::TABLES[0];
		$this->build(entries: [], skipTable: [$table]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The backup has no data for table ' . $table);
		(new ArchiveReader())->verify(zipPath: $this->zip);
	}//end testAManifestMissingATableIsRefused()

	/**
	 * A manifest entry whose file is absent from the zip fails the checksum.
	 *
	 * @return void
	 */
	public function testAManifestEntryWithoutItsFileFails(): void {
		$this->build(entries: [], extra: ['blobs/gone' => hash('sha256', '')]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Checksum mismatch in blobs/gone');
		(new ArchiveReader())->verify(zipPath: $this->zip);
	}//end testAManifestEntryWithoutItsFileFails()

	/**
	 * A complete archive verifies and its manifest comes back.
	 *
	 * @return void
	 */
	public function testACompleteArchiveVerifies(): void {
		$this->build(entries: ['blobs/b1' => 'BLOB']);

		$manifest = (new ArchiveReader())->verify(zipPath: $this->zip);

		$this->assertSame(ArchiveWriter::FORMAT, $manifest['format']);
		$this->assertSame(hash('sha256', 'BLOB'), $manifest['files']['blobs/b1']['sha256']);
	}//end testACompleteArchiveVerifies()

	/**
	 * Rows skip blank lines; a table missing from the zip has no rows.
	 *
	 * @return void
	 */
	public function testRowsSkipBlankLinesAndAMissingTableIsEmpty(): void {
		$this->build(entries: ['tables/secrets.jsonl' => "{\"id\":\"s1\"}\n\n   \n{\"id\":\"s2\"}\n"]);
		$reader = new ArchiveReader();

		$this->assertSame(
			[['id' => 's1'], ['id' => 's2']],
			iterator_to_array($reader->rows(zipPath: $this->zip, table: 'secrets'), false)
		);
		$this->assertSame([], iterator_to_array($reader->rows(zipPath: $this->zip, table: 'no_such_table'), false));
	}//end testRowsSkipBlankLinesAndAMissingTableIsEmpty()

	/**
	 * Blobs yields only the blobs/ entries, keyed by their bare name.
	 *
	 * @return void
	 */
	public function testBlobsYieldsOnlyBlobEntries(): void {
		$this->build(entries: ['blobs/b1' => 'ONE', 'blobs/b2' => 'TWO']);

		$this->assertSame(
			['b1' => 'ONE', 'b2' => 'TWO'],
			iterator_to_array((new ArchiveReader())->blobs(zipPath: $this->zip))
		);
	}//end testBlobsYieldsOnlyBlobEntries()
}//end class
