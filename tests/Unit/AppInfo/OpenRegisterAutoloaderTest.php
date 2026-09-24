<?php

/**
 * Tests for the OpenRegister autoload prelude.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use OCA\Keepiq\AppInfo\OpenRegisterAutoloader;
use PHPUnit\Framework\TestCase;

/**
 * The prelude's whole purpose is that it CANNOT take down the caller.
 *
 * The defect it exists to prevent is an exception escaping the composition
 * root: an `\Error` thrown while resolving `OCA\OpenRegister\AppHost\Bootstrap`
 * aborted Keepiq's entire `Application::register()`, so every listener
 * registered below it silently never ran. A prelude that can itself throw would
 * reintroduce exactly that failure, so "never throws" is the contract under
 * test — on ANY instance, with OpenRegister present or absent.
 */
class OpenRegisterAutoloaderTest extends TestCase {

	/**
	 * The prelude must never throw, whatever the instance looks like.
	 *
	 * This runs in both environments the suite is executed in: with Nextcloud
	 * booted (where OpenRegister may or may not be installed) and with only the
	 * OCP stubs registered (where `\OCP\Server::get()` cannot resolve anything).
	 * Both must be swallowed.
	 *
	 * @return void
	 */
	/**
	 * Hand the process back exactly as it was found.
	 *
	 * `register()` installs a PROCESS-WIDE autoloader. Without this, in CI —
	 * where the server container resolves and `register()` therefore actually
	 * succeeds — every later `class_exists()` for an absent class runs that
	 * closure, and PHPUnit reports unrelated tests as risky for executing code
	 * they do not declare. Measured on keepiq#712: `ConnectionReporterTest::
	 * testTheLookupAnswersNullForAnAbsentClass` went risky on the stable35 leg
	 * while every assertion still passed. It never reproduced locally, because
	 * without a container `register()` returns false and installs nothing.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		OpenRegisterAutoloader::unregister();
		parent::tearDown();

	}//end tearDown()

	public function testRegisterNeverThrows(): void {
		$result = OpenRegisterAutoloader::register();

		$this->assertIsBool(
			$result,
			'The prelude must report success or failure as a bool, never throw.'
		);

	}//end testRegisterNeverThrows()

	/**
	 * Calling the prelude twice must be free and must agree with itself.
	 *
	 * `OC_App::registerAutoloading()` early-returns on an `$alreadyRegistered`
	 * key, so a second call is a no-op. Application::register() may run more
	 * than once in a single process (web + occ share no state, but tests and
	 * repair steps do), and a prelude that failed or threw on the second call
	 * would be a latent bootstrap defect.
	 *
	 * @return void
	 */
	public function testRegisterIsIdempotent(): void {
		$first = OpenRegisterAutoloader::register();
		$second = OpenRegisterAutoloader::register();

		$this->assertSame(
			$first,
			$second,
			'The prelude is idempotent, so repeated calls must agree.'
		);

	}//end testRegisterIsIdempotent()

	/**
	 * Map a class name through the prelude's PSR-4 rule.
	 *
	 * @param string $class The class name.
	 *
	 * @return string|null The mapped file.
	 */
	private function mapped(string $class): ?string {
		$method = new \ReflectionMethod(OpenRegisterAutoloader::class, 'classFile');
		$method->setAccessible(true);

		return $method->invoke(null, '/var/www/apps/openregister', $class);

	}//end mapped()

	/**
	 * An OpenRegister class maps to its file under lib/.
	 *
	 * This is the rule Nextcloud used to own via `addPsr4()`. Since the prelude
	 * stopped calling private API it owns the rule itself, so the rule is pinned
	 * here: namespace separators become directory separators, the prefix is
	 * stripped, and `lib/` is the root.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAnOpenRegisterClassMapsUnderLib(): void {
		$this->assertSame(
			'/var/www/apps/openregister/lib/AppHost/Bootstrap.php',
			$this->mapped('OCA\\OpenRegister\\AppHost\\Bootstrap')
		);

	}//end testAnOpenRegisterClassMapsUnderLib()

	/**
	 * A class this prelude does not own is refused, not guessed at.
	 *
	 * An autoloader that answers for names outside its prefix can shadow the
	 * loader that would have resolved them — including this app's own. The
	 * near-miss `OCA\OpenRegisterExtra\…` is included on purpose: a prefix test
	 * written without the trailing separator matches it.
	 *
	 * @param string $class A class the prelude must not claim.
	 *
	 * @return void
	 *
	 * @dataProvider foreignClassProvider
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAForeignClassIsNotClaimed(string $class): void {
		$this->assertNull($this->mapped($class));

	}//end testAForeignClassIsNotClaimed()

	/**
	 * Class names the prelude must refuse.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function foreignClassProvider(): array {
		return [
			'this app' => ['OCA\\Keepiq\\AppInfo\\Application'],
			'another vendor' => ['OCP\\IRequest'],
			'prefix near-miss' => ['OCA\\OpenRegisterExtra\\Thing'],
			'the bare prefix' => ['OCA\\OpenRegister\\'],
		];

	}//end foreignClassProvider()

	/**
	 * Unregistering when nothing was registered is safe, not an error.
	 *
	 * The degraded path is the common one: on an instance without OpenRegister,
	 * and in any unit run without a server container, `register()` installs
	 * nothing. Teardown still runs. If `unregister()` assumed a loader was
	 * present it would turn every such teardown into a failure, which is a
	 * worse outcome than the leak it exists to prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testUnregisterIsSafeWhenNothingWasRegistered(): void {
		OpenRegisterAutoloader::unregister();
		OpenRegisterAutoloader::unregister();

		// Reaching here is the assertion: neither call raised.
		$this->assertTrue(true);

	}//end testUnregisterIsSafeWhenNothingWasRegistered()

	/**
	 * After unregistering, a fresh register() is allowed to run again.
	 *
	 * `register()` short-circuits on its own `$registered` flag, so if
	 * `unregister()` removed the loader but left the flag set, the prelude
	 * would report success while nothing was on the autoloader — the silent
	 * half-state that produced the 500s in the first place. This pins that the
	 * two are reset together.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testRegisterRunsAgainAfterUnregister(): void {
		$first = OpenRegisterAutoloader::register();
		OpenRegisterAutoloader::unregister();
		$second = OpenRegisterAutoloader::register();

		// Whatever the environment answers, it must answer the SAME both times:
		// a true that becomes false would mean the reset lost the path, and a
		// false that becomes true would mean the first call was short-circuited
		// by a flag rather than by the environment.
		$this->assertSame($first, $second);

	}//end testRegisterRunsAgainAfterUnregister()

	/**
	 * Invoke the prelude's class loader.
	 *
	 * @param string $appPath The fake app root.
	 * @param string $class   The class to resolve.
	 *
	 * @return void
	 */
	private function load(string $appPath, string $class): void {
		$method = new \ReflectionMethod(OpenRegisterAutoloader::class, 'loadClass');
		$method->setAccessible(true);
		$method->invoke(null, $appPath, $class);

	}//end load()

	/**
	 * An OpenRegister class present on disk is actually included.
	 *
	 * This is the work the prelude exists to do, and until now nothing ran it:
	 * inside the registered closure it could only fire if PHP happened to
	 * autoload an `OCA\OpenRegister\…` name mid-suite, which no test can
	 * arrange. A prefix that maps correctly but never includes anything would
	 * look exactly like success at every other assertion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAClassPresentOnDiskIsIncluded(): void {
		$root = sys_get_temp_dir() . '/keepiq-prelude-' . bin2hex(random_bytes(6));
		mkdir($root . '/lib/Probe', 0777, true);
		file_put_contents(
			$root . '/lib/Probe/Marker.php',
			"<?php\nnamespace OCA\\OpenRegister\\Probe;\nclass Marker { public const OK = true; }\n"
		);

		$class = 'OCA\\OpenRegister\\Probe\\Marker';
		$this->assertFalse(class_exists($class, false), 'precondition: not loaded yet');

		$this->load($root, $class);

		$this->assertTrue(class_exists($class, false), 'the prelude must have included the file');

		unlink($root . '/lib/Probe/Marker.php');
		rmdir($root . '/lib/Probe');
		rmdir($root . '/lib');
		rmdir($root);

	}//end testAClassPresentOnDiskIsIncluded()

	/**
	 * A class that maps to a missing file is a silent no-op.
	 *
	 * An autoloader is consulted about every class PHP cannot already see, most
	 * of them somebody else's. Raising here would make this app noisy about
	 * other people's lookups, and on a partial OpenRegister checkout it would
	 * turn a missing file into a fatal instead of a clean "not found".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAMissingFileIsASilentNoOp(): void {
		$this->load('/nonexistent-path-' . bin2hex(random_bytes(4)), 'OCA\\OpenRegister\\Nope\\Missing');

		$this->assertFalse(class_exists('OCA\\OpenRegister\\Nope\\Missing', false));

	}//end testAMissingFileIsASilentNoOp()

	/**
	 * A foreign class is refused before the filesystem is touched.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAForeignClassIsNotLoaded(): void {
		$this->load(sys_get_temp_dir(), 'OCA\\Keepiq\\AppInfo\\Application');

		// Nothing to assert on the filesystem; reaching here without an include
		// or a raise is the behaviour.
		$this->assertTrue(true);

	}//end testAForeignClassIsNotLoaded()

}//end class
