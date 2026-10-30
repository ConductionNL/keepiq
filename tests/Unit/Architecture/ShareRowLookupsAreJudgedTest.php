<?php

/**
 * Every runtime path that reads share rows by copy, by source, by target or
 * by (source, target) pair goes through ShareRowJudge, which asks the pending
 * quarantine scan about each row. This test scans lib/ for calls of the
 * share-target mapper's raw finders on any variable or property typed
 * ShareTargetMapper, and fails on any call outside the allowlist below. Each
 * allowlist entry names the finders it may call and why. An entry that no
 * longer matches a call fails too, so the list cannot rot.
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
 * Source-level guard on the share-target mapper's raw finders.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareRowLookupsAreJudgedTest extends TestCase {

	/**
	 * The finders that read live share rows a runtime path could follow.
	 *
	 * @var list<string>
	 */
	private const FINDERS = [
		'findByRecipientSecret',
		'findBySourceSecretAndTargetUser',
		'findBySourceSecret',
		'findByTargetUser',
	];

	/**
	 * The calls allowed outside ShareRowJudge: file (relative to lib/) =>
	 * finder => why that call does not need the judge.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const ALLOWED = [
		'Service/ShareRowJudge.php' => [
			'findByRecipientSecret' => 'the judged layer itself',
			'findBySourceSecretAndTargetUser' => 'the judged layer itself',
			'findBySourceSecret' => 'the judged layer itself',
			'findByTargetUser' => 'the judged layer itself',
		],
		'Service/ShareQuarantineService.php' => [
			'findBySourceSecretAndTargetUser' => 'admin release: refuses while a live row for the pair exists',
			'findBySourceSecret' => 'admin release: refuses when the copy is itself a live share source',
		],
		'Service/ShareRequestService.php' => [
			'findBySourceSecretAndTargetUser' => 'existence check before a share request; reads no copy and follows no row',
		],
		'Service/TeamFolderShareService.php' => [
			'findBySourceSecretAndTargetUser' => 'missingPairs() and fan-out idempotency: an existing row only skips a pair or a mint',
		],
		'Service/OfflineManifestService.php' => [
			'findByTargetUser' => 'lists the caller\'s own rows for the offline manifest; follows none to a source',
		],
		'Service/DashboardSummaryService.php' => [
			'findByTargetUser' => 'counts the caller\'s received rows; follows none',
		],
		'Service/GdprService.php' => [
			'findBySourceSecret' => 'the account-deletion report counts rows; the cascade it reports judges them',
			'findByTargetUser' => 'the account-deletion report counts rows; the cascade it reports judges them',
		],
		'Service/DirectShareRegistrar.php' => [
			'findBySourceSecretAndTargetUser' => 'register-batch idempotency: an existing row only answers `exists`',
		],
		'Service/ShareService.php' => [
			'findBySourceSecret' => 'lists rows to the owner, a delegate or a write-grade member; every write goes through share-save',
		],
		'Service/AccountShareCleanupService.php' => [
			'findBySourceSecret' => 'account deletion: ShareQuarantineScanner::mayTouchCopy() judges each row before its copy is touched',
		],
		'Service/ShareRevocationService.php' => [
			'findBySourceSecret' => 'revoke-all and cascade: each row is judged (refuseUnjudged/heldBack) before its copy is deleted',
		],
		'Service/ShareRestrictionResolver.php' => [
			'findBySourceSecret' => 'resolveTargets() judges each row (heldBack) before its copy is written',
		],
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
	 * Every raw finder call on a ShareTargetMapper in lib/, as "file => finders".
	 *
	 * @return array<string,array<string,list<int>>> File => finder => line numbers
	 */
	private function rawCalls(): array {
		$calls = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->libPath(), RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($this->libPath()) + 1);
			foreach ($this->callsIn(source: (string)file_get_contents($file->getPathname())) as $finder => $lines) {
				$calls[$relative][$finder] = $lines;
			}
		}

		ksort($calls);

		return $calls;
	}//end rawCalls()

	/**
	 * The raw finder calls in one file, on any variable or property declared
	 * with the type ShareTargetMapper.
	 *
	 * @param string $source The file's source
	 *
	 * @return array<string,list<int>> Finder => line numbers
	 */
	private function callsIn(string $source): array {
		preg_match_all('/\??\\\\?(?:OCA\\\\Keepiq\\\\Db\\\\)?ShareTargetMapper\s+\$(\w+)/', $source, $declared);
		$names = array_unique($declared[1]);
		if ($names === []) {
			return [];
		}

		$pattern = '/\$(?:this\s*->\s*)?(?:'.implode('|', array_map('preg_quote', $names)).')\s*\??->\s*('
			.implode('|', self::FINDERS).')\s*\(/';
		preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

		$calls = [];
		foreach ($matches[1] as [$finder, $offset]) {
			$calls[$finder][] = substr_count(substr($source, 0, $offset), "\n") + 1;
		}

		return $calls;
	}//end callsIn()

	/**
	 * No runtime path calls a raw finder outside the allowlist.
	 *
	 * @return void
	 */
	public function testNoPathReadsShareRowsAroundTheJudge(): void {
		$offending = [];
		foreach ($this->rawCalls() as $file => $finders) {
			foreach ($finders as $finder => $lines) {
				if (isset(self::ALLOWED[$file][$finder]) === false) {
					$offending[] = 'lib/'.$file.':'.implode(',', $lines).' '.$finder.'()';
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
	 * Every allowlist entry still matches a call, so the list does not rot.
	 *
	 * @return void
	 */
	public function testEveryAllowedCallStillExists(): void {
		$calls = $this->rawCalls();
		$stale = [];
		foreach (self::ALLOWED as $file => $finders) {
			foreach (array_keys($finders) as $finder) {
				if (isset($calls[$file][$finder]) === false) {
					$stale[] = $file.' '.$finder;
				}
			}
		}

		$this->assertSame([], $stale, 'Remove the allowlist entries that no longer match a call.');
	}//end testEveryAllowedCallStillExists()

	/**
	 * The scan sees calls through a promoted property, a nullsafe call and a
	 * plain variable, so a raw call cannot hide behind the syntax.
	 *
	 * @return void
	 */
	public function testTheScanSeesEveryCallShape(): void {
		$source = '<?php class X { public function __construct(private ?ShareTargetMapper $rows, ShareTargetMapper $other) {'
			.' $this->rows?->findByRecipientSecret(recipientSecretId: "a"); $other->findByTargetUser("b");'
			.' $this -> rows -> findBySourceSecret("c"); } }';

		$this->assertSame(
			['findByRecipientSecret' => [1], 'findByTargetUser' => [1], 'findBySourceSecret' => [1]],
			$this->callsIn(source: $source)
		);
	}//end testTheScanSeesEveryCallShape()
}//end class
