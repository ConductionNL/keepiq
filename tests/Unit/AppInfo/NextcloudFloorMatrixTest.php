<?php

/**
 * Tests that the declared Nextcloud floor and the tested matrix agree.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * `appinfo/info.xml` states which Nextcloud versions this app supports.
 * `.github/workflows/code-quality.yml` states which it actually runs against.
 * Until this test, nothing held the two together in this repository, and the
 * fleet has now drifted in both directions more than once in a single week —
 * openregister raised its floor to 32 (#2378), reverted it to 28 (#2380) and
 * raised it again (#2384) inside 48 hours, and every consumer repo had to be
 * re-measured each time.
 *
 * Two failure modes, and this test catches both:
 *
 *   - A tested leg BELOW the declared floor is a red (or, worse, a green)
 *     about a configuration the app does not claim to support. The floor is
 *     enforced at INSTALL time, so on such a leg `occ app:enable` refuses;
 *     the shared workflow downgrades that to a `::warning::` and carries on,
 *     and the job then dies ~70 seconds later on missing schemas — which reads
 *     like a migration fault and sends you to entirely the wrong file.
 *   - A declared floor with NO tested leg at or above it means the supported
 *     range is asserted but never exercised.
 *
 * This asserts on each ITEM (every ref in the matrix), not on the container
 * (the matrix merely being non-empty), and it proves its own inputs were
 * readable before drawing any conclusion from their contents — a scanner that
 * silently found nothing would otherwise pass every assertion vacuously.
 */
class NextcloudFloorMatrixTest extends TestCase {

	/**
	 * Read the declared `<nextcloud min-version>` from appinfo/info.xml.
	 *
	 * @return int The declared major version.
	 */
	private function declaredFloor(): int {
		$path = __DIR__ . '/../../../appinfo/info.xml';
		$this->assertFileExists($path, 'appinfo/info.xml must exist to be checked');

		// Read the bytes ourselves and parse the STRING. `simplexml_load_file()`
		// cannot be used here: Nextcloud's `lib/base.php` installs
		//
		//     libxml_set_external_entity_loader(static fn () => null);
		//
		// to stop any XML processing pulling in external entities, and
		// `simplexml_load_file()` resolves the file it was handed THROUGH that
		// same resolver. So under a Nextcloud bootstrap it returns false with
		// "Failed to load external entity because the resolver function returned
		// null" — for a perfectly well-formed local file.
		//
		// That is exactly what happened: these four tests were green locally,
		// where `tests/bootstrap-unit.php` falls back to OCP stubs and never
		// loads base.php, and red on BOTH PHP legs in CI, where the suite runs
		// inside the Nextcloud container and base.php IS loaded. Both legs
		// failing identically was the signal that the test — not the floor —
		// was wrong. `file_get_contents()` is a plain filesystem read and does
		// not go near libxml's resolver.
		$raw = file_get_contents($path);
		$this->assertIsString($raw, 'appinfo/info.xml must be readable');

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($raw);
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertNotFalse(
			$xml,
			'appinfo/info.xml must parse as XML. libxml said: '
			. implode(
				'; ',
				array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors)
			)
		);

		// `//dependencies/nextcloud`, never a bare search for `min-version`:
		// this same file carries three <database> elements, the first of which
		// declares min-version="10" (Postgres), and a COMMENT that discusses
		// `<nextcloud min-version="32"/>` in prose. A text scan reads any of
		// those as the floor.
		$nodes = $xml->xpath('//dependencies/nextcloud');
		$this->assertNotEmpty($nodes, 'appinfo/info.xml declares no <nextcloud> dependency');
		$this->assertCount(
			1,
			$nodes,
			'appinfo/info.xml declares more than one <nextcloud> dependency. '
			. 'A second, contradictory declaration must not be able to hide behind the first.'
		);

		$min = (string)$nodes[0]['min-version'];
		$this->assertMatchesRegularExpression(
			'/^\d+$/',
			$min,
			'nextcloud min-version must be a bare major version'
		);

		return (int)$min;
	}//end declaredFloor()

	/**
	 * Read the `nextcloud-test-refs` legs from the quality workflow.
	 *
	 * Parsed from the ASSIGNMENT LINE only, never from surrounding prose: these
	 * workflows carry comments that name refs while explaining they were
	 * REMOVED, so a comment-blind scan reads legs that do not exist.
	 *
	 * @return array<int, int> The major version of every tested leg.
	 */
	private function testedRefs(): array {
		$path = __DIR__ . '/../../../.github/workflows/code-quality.yml';
		$this->assertFileExists(
			$path,
			'code-quality.yml must be readable from the test tree. If it is not, this '
			. 'test cannot answer the question it was written to answer, and its result '
			. 'must not be read as a pass.'
		);

		$workflow = file_get_contents($path);
		$this->assertIsString($workflow, '.github/workflows/code-quality.yml must be readable');

		$matched = preg_match(
			"/^\s*nextcloud-test-refs:\s*'(?<json>\[[^\]]*\])'/m",
			$workflow,
			$matches
		);
		$this->assertSame(
			1,
			$matched,
			'Could not find a `nextcloud-test-refs:` line in code-quality.yml. If the key '
			. 'was renamed, or the input was dropped so the shared workflow default applies, '
			. 'this test is scanning for something that no longer exists and its green would '
			. 'be meaningless. NOTE: omitting the input is not neutral — the shared '
			. 'quality.yml default is ["stable31", "stable32"].'
		);

		$refs = json_decode($matches['json'], true);
		$this->assertIsArray($refs, 'nextcloud-test-refs must be a JSON array');

		$majors = [];
		foreach ($refs as $ref) {
			$this->assertMatchesRegularExpression(
				'/^stable(\d+)$/',
				(string)$ref,
				sprintf('Unrecognised Nextcloud test ref "%s"', (string)$ref)
			);
			preg_match('/^stable(\d+)$/', (string)$ref, $m);
			$majors[] = (int)$m[1];
		}

		return $majors;
	}//end testedRefs()

	/**
	 * The scanners must actually find something before any absence claim
	 * derived from them can be believed. A check that did not run looks
	 * exactly like one that passed.
	 *
	 * @return void
	 */
	public function testBothDeclarationsAreActuallyReadable(): void {
		$this->assertGreaterThan(0, $this->declaredFloor());
		$this->assertNotEmpty(
			$this->testedRefs(),
			'The tested-ref list parsed as empty. An empty list would make every '
			. 'assertion below pass vacuously.'
		);
	}//end testBothDeclarationsAreActuallyReadable()

	/**
	 * No CI leg may target a Nextcloud below the declared floor.
	 *
	 * @return void
	 */
	public function testNoTestedLegIsBelowTheDeclaredFloor(): void {
		$floor = $this->declaredFloor();
		$below = [];

		foreach ($this->testedRefs() as $major) {
			if ($major < $floor) {
				$below[] = 'stable' . $major;
			}
		}

		$this->assertSame(
			[],
			$below,
			sprintf(
				'appinfo/info.xml declares a Nextcloud floor of %d, but CI still runs '
				. 'against %s. Either drop the leg or lower the floor — a leg below the '
				. 'floor tests a configuration this app does not support, so neither its '
				. 'red nor its green means anything.',
				$floor,
				implode(', ', $below)
			)
		);
	}//end testNoTestedLegIsBelowTheDeclaredFloor()

	/**
	 * At least one CI leg must sit at or above the declared floor, so the
	 * supported range is exercised rather than merely asserted.
	 *
	 * @return void
	 */
	public function testTheDeclaredFloorIsActuallyExercised(): void {
		$floor = $this->declaredFloor();
		$refs = $this->testedRefs();

		$atOrAbove = array_filter($refs, static fn (int $major): bool => $major >= $floor);

		$this->assertNotEmpty(
			$atOrAbove,
			sprintf(
				'appinfo/info.xml declares a floor of %d but no CI leg runs at or above '
				. 'it (legs: %s). The declared support range is entirely untested.',
				$floor,
				implode(', ', array_map(static fn (int $m): string => 'stable' . $m, $refs))
			)
		);
	}//end testTheDeclaredFloorIsActuallyExercised()

	/**
	 * The fleet-wide floor is 32, so that PHP 8.3 is guaranteed by the
	 * platform. Pinning the literal is what stops a silent lowering: the
	 * agreement assertions above are satisfied just as well by a floor of 28
	 * with a stable28 leg, which is precisely the drift that has already
	 * happened once and been reverted.
	 *
	 * @return void
	 */
	public function testTheFloorIsTheFleetWideThirtyTwo(): void {
		$this->assertSame(
			32,
			$this->declaredFloor(),
			'The fleet standardises on Nextcloud 32 so it can require PHP 8.3. Change '
			. 'this expectation deliberately, with a reason, rather than to make a red '
			. 'build green.'
		);
	}//end testTheFloorIsTheFleetWideThirtyTwo()

}//end class
