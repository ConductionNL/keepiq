<?php

/**
 * Source-level invariants for PHPMD suppression hygiene.
 *
 * A `@SuppressWarnings` tag is a claim that a finding is unavoidable. Without a
 * written reason it is indistinguishable from one that was never adjudicated —
 * and a reader has no way to tell a real external constraint from debt someone
 * silenced. This suite locks in the two invariants the repo-wide suppression
 * audit established, so neither can regress quietly.
 *
 * It scans source rather than exercising behaviour, the same shape as
 * {@see \OCA\Keepiq\Tests\Unit\Service\KeyGeneratorServiceTest::testSourceUsesNoWeakRandomness()}.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit
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

namespace OCA\Keepiq\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tests the source-level invariants around PHPMD suppressions.
 */
class SuppressionHygieneTest extends TestCase {

	/**
	 * Shortest acceptable reason, in characters.
	 *
	 * Long enough that a placeholder ("ok", "n/a", "see above") cannot pass,
	 * short enough that a real one-line justification is not rejected.
	 *
	 * @var int
	 */
	private const MIN_REASON_LENGTH = 12;

	/**
	 * Absolute path to the app's lib/ directory.
	 *
	 * @return string The lib path.
	 */
	private function libPath(): string {
		return dirname(__DIR__, 2) . '/lib';
	}//end libPath()

	/**
	 * Every PHP file under lib/, as absolute paths.
	 *
	 * @return string[] The file paths.
	 */
	private function libFiles(): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->libPath(), RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);

		return $files;
	}//end libFiles()

	/**
	 * The scan must actually reach the source tree.
	 *
	 * Without this, a wrong path would make every assertion below pass
	 * vacuously — an empty scan and a clean one look identical.
	 *
	 * @return void
	 */
	public function testScanReachesTheSourceTree(): void {
		$files = $this->libFiles();

		$this->assertGreaterThan(100, count($files), 'lib/ scan returned an implausibly small file list');

		$tagged = 0;
		foreach ($files as $file) {
			if (str_contains((string)file_get_contents($file), '@SuppressWarnings') === true) {
				$tagged++;
			}
		}

		// The audit left suppressions in place where they are genuinely
		// interface- or API-mandated, so a zero here means the scan is broken,
		// not that the debt vanished.
		$this->assertGreaterThan(0, $tagged, 'no @SuppressWarnings found at all — the scan is not working');

	}//end testScanReachesTheSourceTree()

	/**
	 * Every @SuppressWarnings under lib/ carries a written reason.
	 *
	 * The reason must sit on the tag's own line, after the closing paren —
	 * that is where a reader looks, and it is what survives a docblock being
	 * reflowed.
	 *
	 * @return void
	 */
	public function testEverySuppressionCarriesAWrittenReason(): void {
		$offenders = [];

		foreach ($this->libFiles() as $file) {
			$lines = (array)file($file, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

			foreach ($lines as $index => $line) {
				if (preg_match('/@SuppressWarnings\(\s*PHPMD\.[A-Za-z]+\s*\)(.*)$/', (string)$line, $matches) !== 1) {
					continue;
				}

				$reason = trim((string)$matches[1], " \t*-—:");
				if (strlen($reason) >= self::MIN_REASON_LENGTH) {
					continue;
				}

				$relative = substr($file, (strlen($this->libPath()) + 1));
				$offenders[] = 'lib/' . $relative . ':' . ($index + 1);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'@SuppressWarnings without a written reason (>= ' . self::MIN_REASON_LENGTH . " chars on the tag's own line):\n"
			. implode("\n", $offenders)
		);

	}//end testEverySuppressionCarriesAWrittenReason()

	/**
	 * There must be no phpmd.baseline.xml anywhere in the repo.
	 *
	 * PHPMD AUTO-DISCOVERS this filename: a baseline stays active even after
	 * the `baseline` CLI flag is removed from composer.json, so its mere
	 * presence silently subtracts findings from every leg of `composer phpmd`.
	 * The three rulesets are meant to be the whole truth; a baseline would make
	 * a green run mean something narrower than it appears to.
	 *
	 * @return void
	 */
	public function testNoAutoDiscoveredPhpmdBaselineExists(): void {
		$root = dirname(__DIR__, 2);
		$found = [];

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			$path = $file->getPathname();
			if (str_contains($path, '/vendor/') === true || str_contains($path, '/node_modules/') === true) {
				continue;
			}

			if ($file->isFile() === true && $file->getFilename() === 'phpmd.baseline.xml') {
				$found[] = substr($path, (strlen($root) + 1));
			}
		}

		$this->assertSame([], $found, 'PHPMD auto-discovers phpmd.baseline.xml; none may exist in this repo');

	}//end testNoAutoDiscoveredPhpmdBaselineExists()
}//end class
