<?php

/**
 * Every runtime path that reads share rows goes through ShareRowJudge, which
 * asks the pending quarantine scan about each row, or is listed below with
 * the reason it needs no judge. This test scans lib/ for:
 *
 * - calls of a share-row finder of ShareTargetMapper or
 *   BulkGrantShareTargetMapper on anything typed as one of them (a promoted
 *   or plain parameter, a property, a nullable or union type, an aliased
 *   import) or fetched from the container (`Server::get(...::class)`,
 *   `->get(...::class)`);
 * - query-builder reads of the share-targets table by name outside the
 *   mappers.
 *
 * The allowlist is per call site: a file, a finder and the number of calls
 * of it in that file, so a new call in an allowed file fails as well. An
 * entry that no longer matches fails too, so the list cannot rot.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Architecture
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

namespace OCA\Keepiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Source-level guard on the share-row finders.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareRowLookupsAreJudgedTest extends TestCase {

	/**
	 * The mapper classes whose finders return live share rows, with those finders.
	 *
	 * @var array<string,list<string>>
	 */
	private const FINDERS = [
		'ShareTargetMapper' => [
			'findByRecipientSecret',
			'findBySourceSecretAndTargetUser',
			'findBySourceSecret',
			'findByTargetUser',
			'findById',
			'findPage',
			'findEndingBetween',
		],
		'BulkGrantShareTargetMapper' => [
			'findByGroupShare',
			'findByTeamFolderAndTargetUser',
			'findByTeamFolder',
		],
	];

	/**
	 * The share-targets table, as a query names it.
	 *
	 * @var string
	 */
	private const TABLE_PATTERN = '/[\'"](?:keepiq_)?share_targets[\'"]/';

	/**
	 * The calls allowed outside ShareRowJudge: file (relative to lib/) =>
	 * finder => [number of calls, why they need no judge].
	 *
	 * @var array<string,array<string,array{0:int,1:string}>>
	 */
	private const ALLOWED = [
		'Service/ShareRowJudge.php' => [
			'findByRecipientSecret' => [1, 'the judged layer itself'],
			'findBySourceSecretAndTargetUser' => [1, 'the judged layer itself'],
			'findBySourceSecret' => [1, 'the judged layer itself'],
			'findByTargetUser' => [1, 'the judged layer itself'],
		],
		'Service/ShareQuarantineService.php' => [
			'findBySourceSecretAndTargetUser' => [1, 'admin release: refuses while a live row for the pair exists'],
			'findBySourceSecret' => [1, 'admin release: refuses when the copy is itself a live share source'],
		],
		'Service/ShareRequestService.php' => [
			'findBySourceSecretAndTargetUser' => [2, 'existence check before a share request; reads no copy and follows no row'],
		],
		'Service/TeamFolderShareService.php' => [
			'findBySourceSecretAndTargetUser' => [2, 'missingPairs() and fan-out idempotency: an existing row only skips a pair or a mint'],
			'findByTeamFolder' => [2, 'confirmers() lists user ids for the folder dialog; folderRows() hands its rows to the judge'],
			'findByTeamFolderAndTargetUser' => [1, 'folderRows() hands its rows to the judge'],
		],
		'Service/OfflineManifestService.php' => [
			'findByTargetUser' => [1, 'lists the caller\'s own rows for the offline manifest; follows none to a source'],
		],
		'Service/DashboardSummaryService.php' => [
			'findByTargetUser' => [1, 'counts the caller\'s received rows; follows none'],
		],
		'Service/GdprService.php' => [
			'findBySourceSecret' => [1, 'the account-deletion report counts rows; the cascade it reports judges them'],
			'findByTargetUser' => [1, 'the account-deletion report counts rows; the cascade it reports judges them'],
		],
		'Service/DirectShareRegistrar.php' => [
			'findBySourceSecretAndTargetUser' => [1, 'register-batch idempotency: an existing row only answers `exists`'],
		],
		'Service/ShareService.php' => [
			'findBySourceSecret' => [2, 'lists rows to the owner, a delegate or a write-grade member; every write goes through share-save'],
			'findById' => [1, 'restriction change of one row: ShareQuarantineScanner::assertNotHeldBack() judges it first'],
		],
		'Service/AccountShareCleanupService.php' => [
			'findBySourceSecret' => [1, 'account deletion: each row is judged (isHeldByTarget, heldBack) before its copy is touched'],
		],
		'Service/ShareRevocationService.php' => [
			'findBySourceSecret' => [2, 'revoke-all and cascade: each row is judged (refuseUnjudged/heldBack) before its copy is deleted'],
			'findById' => [1, 'revoke of one row: ShareQuarantineScanner::assertNotHeldBack() judges it first'],
		],
		'Service/ShareRestrictionResolver.php' => [
			'findBySourceSecret' => [1, 'resolveTargets() judges each row (heldBack) before its copy is written'],
			'findPage' => [1, 'the full resolve once the scan completes: resolveTargets() judges each row'],
		],
		'Service/ShareExpiryService.php' => [
			'findById' => [1, 'reads an ended row again; it is revoked through ShareRevocationService, which judges it'],
		],
		'Service/ExpiredGrantRemover.php' => [
			'findEndingBetween' => [1, 'each ended row is revoked through ShareRevocationService::revokeShare(), which judges it'],
		],
		'Service/GroupShareService.php' => [
			'findByGroupShare' => [4, 'group revoke, member leave and restriction change judge each row (refuseUnjudged/heldBack) first'],
		],
		'Repair/SeedDevelopmentShares.php' => [
			'findById' => [2, 'development seed: checks whether its own fixed row already exists'],
		],
	];

	/**
	 * Files that may name the share-targets table in a query, and why.
	 *
	 * @var array<string,string>
	 */
	private const TABLE_ALLOWED = [
		'Service/ShareQuarantineScanner.php' => 'the scan itself reads every row, quarantined or not',
		'Service/ComplianceReportService.php' => 'counts live rows for the compliance report; follows none',
		'Backup/BackupTableRegistry.php' => 'backup and restore copy the table whole; a restore reopens the scan',
	];

	/**
	 * Absolute path to lib/.
	 *
	 * @return string
	 */
	private function libPath(): string {
		return dirname(__DIR__, 3).'/lib';
	}//end libPath()

	/**
	 * Every PHP file under lib/, relative to it, with its source.
	 *
	 * @return array<string,string>
	 */
	private function libSources(): array {
		$sources = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->libPath(), RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$sources[substr($file->getPathname(), strlen($this->libPath()) + 1)] = (string)file_get_contents($file->getPathname());
			}
		}

		ksort($sources);

		return $sources;
	}//end libSources()

	/**
	 * Every raw finder call in lib/ outside the mappers, as "file => finder => lines".
	 *
	 * @return array<string,array<string,list<int>>>
	 */
	private function rawCalls(): array {
		$calls = [];
		foreach ($this->libSources() as $file => $source) {
			if (str_starts_with($file, 'Db/') === true) {
				continue;
			}

			$found = $this->callsIn(source: $source);
			if ($found !== []) {
				$calls[$file] = $found;
			}
		}

		return $calls;
	}//end rawCalls()

	/**
	 * Every query naming the share-targets table in lib/ outside lib/Db/ and
	 * the migrations, as "file => lines".
	 *
	 * @return array<string,list<int>>
	 */
	private function tableReads(): array {
		$reads = [];
		foreach ($this->libSources() as $file => $source) {
			if (str_starts_with($file, 'Db/') === true || str_starts_with($file, 'Migration/') === true) {
				continue;
			}

			$lines = $this->linesMatching(pattern: self::TABLE_PATTERN, source: $source);
			if ($lines !== []) {
				$reads[$file] = $lines;
			}
		}

		return $reads;
	}//end tableReads()

	/**
	 * The raw finder calls in one file: on any variable or property typed as
	 * one of the mappers (also nullable, in a union or under an alias), on a
	 * variable assigned from the container, and on a container lookup itself.
	 *
	 * @param string $source The file's source
	 *
	 * @return array<string,list<int>> Finder => line numbers
	 */
	private function callsIn(string $source): array {
		$calls = [];
		foreach (self::FINDERS as $class => $finders) {
			$aliases = [$class];
			if (preg_match_all('/^use\s+\\\\?OCA\\\\Keepiq\\\\Db\\\\'.$class.'\s+as\s+(\w+)\s*;/m', $source, $aliased) > 0) {
				$aliases = array_merge($aliases, $aliased[1]);
			}

			$type = '(?<![\w\\\\])\\\\?(?:OCA\\\\Keepiq\\\\Db\\\\)?(?:'.implode('|', $aliases).')(?![\w\\\\])';
			preg_match_all('/(?:\?|\bnull\s*\|\s*)?'.$type.'(?:\s*\|\s*null)?\s+(?:&\s*)?\$(\w+)/', $source, $typed);
			preg_match_all('/\$(\w+)\s*=\s*[^;]*?(?:::|->)get\(\s*'.$type.'::class\s*\)/', $source, $fetched);
			$names = array_unique(array_merge($typed[1], $fetched[1]));

			$receivers = ['(?:::|->)get\(\s*'.$type.'::class\s*\)\s*\)?'];
			if ($names !== []) {
				$receivers[] = '\$(?:this\s*->\s*)?(?:'.implode('|', array_map('preg_quote', $names)).')\b';
			}

			$pattern = '/(?:'.implode('|', $receivers).')\s*\??->\s*('.implode('|', $finders).')\s*\(/';
			preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
			foreach ($matches[1] as [$finder, $offset]) {
				$calls[$finder][] = substr_count(substr($source, 0, $offset), "\n") + 1;
			}
		}//end foreach

		return $calls;
	}//end callsIn()

	/**
	 * The line numbers in a source that match a pattern.
	 *
	 * @param string $pattern The pattern
	 * @param string $source  The source
	 *
	 * @return list<int>
	 */
	private function linesMatching(string $pattern, string $source): array {
		$lines = [];
		preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [, $offset]) {
			$lines[] = substr_count(substr($source, 0, $offset), "\n") + 1;
		}

		return $lines;
	}//end linesMatching()

	/**
	 * No runtime path calls a share-row finder outside the allowlist, and an
	 * allowed file calls it exactly as often as listed.
	 *
	 * @return void
	 */
	public function testNoPathReadsShareRowsAroundTheJudge(): void {
		$offending = [];
		foreach ($this->rawCalls() as $file => $finders) {
			foreach ($finders as $finder => $lines) {
				$allowed = (self::ALLOWED[$file][$finder][0] ?? 0);
				if (count($lines) !== $allowed) {
					$offending[] = 'lib/'.$file.':'.implode(',', $lines).' '.$finder.'() called '.count($lines).' time(s), '.$allowed.' allowed';
				}
			}
		}

		$this->assertSame(
			[],
			$offending,
			"Read share rows through ShareRowJudge, which asks the pending quarantine scan about each row,\n"
				.'or add the call to ALLOWED with the reason it needs no judge.'
		);
	}//end testNoPathReadsShareRowsAroundTheJudge()

	/**
	 * No runtime path queries the share-targets table by name outside the
	 * mappers and the listed files.
	 *
	 * @return void
	 */
	public function testNoPathQueriesTheShareTargetsTableAroundTheMappers(): void {
		$offending = [];
		foreach ($this->tableReads() as $file => $lines) {
			if (isset(self::TABLE_ALLOWED[$file]) === false) {
				$offending[] = 'lib/'.$file.':'.implode(',', $lines);
			}
		}

		$this->assertSame([], $offending, 'Read share rows through the mappers and ShareRowJudge, not by table name.');
	}//end testNoPathQueriesTheShareTargetsTableAroundTheMappers()

	/**
	 * Every allowlist entry still matches, so the lists do not rot.
	 *
	 * @return void
	 */
	public function testEveryAllowedCallStillExists(): void {
		$calls = $this->rawCalls();
		$reads = $this->tableReads();
		$stale = [];
		foreach (self::ALLOWED as $file => $finders) {
			foreach (array_keys($finders) as $finder) {
				if (isset($calls[$file][$finder]) === false) {
					$stale[] = $file.' '.$finder;
				}
			}
		}

		foreach (array_keys(self::TABLE_ALLOWED) as $file) {
			if (isset($reads[$file]) === false) {
				$stale[] = $file.' (table)';
			}
		}

		$this->assertSame([], $stale, 'Remove the allowlist entries that no longer match a call.');
	}//end testEveryAllowedCallStillExists()

	/**
	 * The scan sees calls through a promoted property, a nullable, union or
	 * aliased type, a plain variable, a container lookup and a variable
	 * assigned from one, for both mappers, so a raw call cannot hide behind
	 * the syntax; and a query naming the table.
	 *
	 * @return void
	 */
	public function testTheScanSeesEveryCallShape(): void {
		$source = implode("\n", [
			'<?php',
			'use OCA\\Keepiq\\Db\\ShareTargetMapper as Rows;',
			'class X { public function __construct(private ?ShareTargetMapper $rows, ShareTargetMapper|null $other,',
			' null|Rows $aliased, private BulkGrantShareTargetMapper $bulk) {',
			' $this->rows?->findByRecipientSecret(recipientSecretId: "a");',
			' $other->findByTargetUser("b");',
			' $this -> rows -> findBySourceSecret("c");',
			' $aliased->findById("d");',
			' Server::get(ShareTargetMapper::class)->findPage("", 1);',
			' $fetched = \\OCP\\Server::get(\\OCA\\Keepiq\\Db\\ShareTargetMapper::class);',
			' $fetched->findEndingBetween(null, $now);',
			' $this->bulk->findByGroupShare("e");',
			' $this->bulk->findByTeamFolderAndTargetUser("f", "g");',
			' } }',
		]);

		$this->assertSame(
			[
				'findByRecipientSecret' => [5],
				'findBySourceSecret' => [7],
				'findByTargetUser' => [6],
				'findById' => [8],
				'findPage' => [9],
				'findEndingBetween' => [11],
				'findByGroupShare' => [12],
				'findByTeamFolderAndTargetUser' => [13],
			],
			$this->sorted(calls: $this->callsIn(source: $source))
		);
		$this->assertSame([2], $this->linesMatching(pattern: self::TABLE_PATTERN, source: "<?php\n\$qb->from('keepiq_share_targets');"));
	}//end testTheScanSeesEveryCallShape()

	/**
	 * Calls in the order of FINDERS, for a stable comparison.
	 *
	 * @param array<string,list<int>> $calls The calls
	 *
	 * @return array<string,list<int>>
	 */
	private function sorted(array $calls): array {
		$order = array_merge(...array_values(self::FINDERS));

		return array_filter(array_replace(array_fill_keys($order, null), $calls), static fn ($lines): bool => $lines !== null);
	}//end sorted()
}//end class
