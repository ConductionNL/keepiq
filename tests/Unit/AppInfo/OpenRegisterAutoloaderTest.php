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
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
	public function testRegisterNeverThrows(): void {
		$result = OpenRegisterAutoloader::register();

		$this->assertIsBool(
			$result,
			'The prelude must report success or failure as a bool, never throw.'
		);

	}//end testRegisterNeverThrows()

	/**
	 * A second register() is a no-op: it must not stack a second closure.
	 *
	 * `register()` short-circuits on its own `$registered` flag, because
	 * `spl_autoload_register()` has no early-return of its own. Without the
	 * flag every call stacks another closure, and `unregister()` only holds the
	 * last handle, so the rest leak for the life of the process — per request
	 * under a worker, per test in a suite. Two calls agreeing on their return
	 * value is not enough to see that, so this counts the chain.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testRegisterIsIdempotent(): void {
		$root = $this->fakeApp();
		$appManager = $this->appManager(enabled: true, path: $root);
		$baseline = count(spl_autoload_functions());

		try {
			$this->assertTrue(OpenRegisterAutoloader::register($appManager));
			$this->assertTrue(OpenRegisterAutoloader::register($appManager));
			$this->assertCount($baseline + 1, spl_autoload_functions(), 'a second register() must not stack a second closure');
		} finally {
			$this->removeFakeApp($root);
		}

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
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
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
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
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
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testUnregisterIsSafeWhenNothingWasRegistered(): void {
		$before = spl_autoload_functions();

		OpenRegisterAutoloader::unregister();
		OpenRegisterAutoloader::unregister();

		$this->assertSame($before, spl_autoload_functions(), 'a no-op unregister must not touch the chain');

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
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testRegisterRunsAgainAfterUnregister(): void {
		$root = $this->fakeApp();
		$appManager = $this->appManager(enabled: true, path: $root);
		$baseline = count(spl_autoload_functions());

		try {
			$this->assertTrue(OpenRegisterAutoloader::register($appManager));
			$this->assertCount($baseline + 1, spl_autoload_functions(), 'register must install the closure');

			OpenRegisterAutoloader::unregister();
			$this->assertCount($baseline, spl_autoload_functions(), 'unregister must take the closure off the chain');

			// A flag left set by unregister() would short-circuit here and
			// report true while nothing is installed — so count, don't compare.
			$this->assertTrue(OpenRegisterAutoloader::register($appManager));
			$this->assertCount($baseline + 1, spl_autoload_functions(), 'a re-register after unregister must actually re-install');
		} finally {
			$this->removeFakeApp($root);
		}

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
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testAClassPresentOnDiskIsIncluded(): void {
		$root = $this->fakeApp();
		file_put_contents(
			$root . '/lib/Probe/Marker.php',
			"<?php\nnamespace OCA\\OpenRegister\\Probe;\nclass Marker { public const OK = true; }\n"
		);

		try {
			$class = 'OCA\\OpenRegister\\Probe\\Marker';
			$this->assertFalse(class_exists($class, false), 'precondition: not loaded yet');

			$this->load($root, $class);

			$this->assertTrue(class_exists($class, false), 'the prelude must have included the file');
		} finally {
			$this->removeFakeApp($root);
		}

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
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testAMissingFileIsASilentNoOp(): void {
		$this->load('/nonexistent-path-' . bin2hex(random_bytes(4)), 'OCA\\OpenRegister\\Nope\\Missing');

		$this->assertFalse(class_exists('OCA\\OpenRegister\\Nope\\Missing', false));

	}//end testAMissingFileIsASilentNoOp()

	/**
	 * A foreign class is refused before the filesystem is touched.
	 *
	 * The name is the near-miss `OCA\OpenRegisterExtra\…`, and a file is planted
	 * exactly where a prefix test without its trailing separator would map it,
	 * so the assertion fails if the prefix check is weakened.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testAForeignClassIsNotLoaded(): void {
		$root = $this->fakeApp();
		mkdir($root . '/lib/Extra/Probe', 0700, true);
		file_put_contents($root . '/lib/Extra/Probe/Foreign.php', "<?php\n");

		try {
			$before = get_included_files();
			$this->load($root, 'OCA\\OpenRegisterExtra\\Probe\\Foreign');
			$this->assertSame($before, get_included_files(), 'a foreign class must not cause an include');
		} finally {
			$this->removeFakeApp($root);
		}

	}//end testAForeignClassIsNotLoaded()

	/**
	 * An installed-but-disabled OpenRegister is treated exactly like an absent one.
	 *
	 * `getAppPath()` is a pure path lookup and does not consult enabled state,
	 * so without this check disabling OpenRegister (for containment, or a bad
	 * release) would still load its code into this app's process.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testADisabledOpenRegisterIsNotRegistered(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('openregister')->willReturn(false);
		$appManager->expects($this->never())->method('getAppPath');

		$baseline = count(spl_autoload_functions());

		$this->assertFalse(OpenRegisterAutoloader::register($appManager));
		$this->assertCount($baseline, spl_autoload_functions(), 'a disabled OpenRegister must not be put on the autoloader');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		OpenRegisterAutoloader::reportFailure($logger);

	}//end testADisabledOpenRegisterIsNotRegistered()

	/**
	 * An unexpected failure is swallowed at register() time and logged once at boot.
	 *
	 * This is the fail mode that hid the Nextcloud 35 500s: every failure
	 * collapsed into an unlogged false. It must still never throw, but it must
	 * leave one line in the log.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testAnUnexpectedFailureIsLoggedOnce(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);
		$appManager->method('getAppPath')->willThrowException(new \RuntimeException('boom'));

		$this->assertFalse(OpenRegisterAutoloader::register($appManager));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('AppHost wiring was skipped'),
				$this->callback(static fn (array $ctx): bool => ($ctx['exception'] ?? null) instanceof \RuntimeException)
			);

		OpenRegisterAutoloader::reportFailure($logger);
		// A second report in the same process must not repeat the line.
		OpenRegisterAutoloader::reportFailure($logger);

	}//end testAnUnexpectedFailureIsLoggedOnce()

	/**
	 * An OpenRegister that is enabled but missing on disk stays quiet.
	 *
	 * Nextcloud's own Coordinator already logs an enabled app it cannot find, so
	 * a second line here would only be noise. A genuinely absent OpenRegister
	 * never gets this far: it is not enabled, which
	 * {@see testADisabledOpenRegisterIsNotRegistered} covers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testAnEnabledButMissingOpenRegisterIsNotLogged(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);
		$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException('openregister'));

		$this->assertFalse(OpenRegisterAutoloader::register($appManager));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		OpenRegisterAutoloader::reportFailure($logger);

	}//end testAnEnabledButMissingOpenRegisterIsNotLogged()

	/**
	 * unregister() also forgets a recorded failure, so it cannot leak into a later test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testUnregisterForgetsARecordedFailure(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);
		$appManager->method('getAppPath')->willThrowException(new \RuntimeException('boom'));

		OpenRegisterAutoloader::register($appManager);
		OpenRegisterAutoloader::unregister();

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		OpenRegisterAutoloader::reportFailure($logger);

	}//end testUnregisterForgetsARecordedFailure()

	/**
	 * An enabled OpenRegister without a lib/ directory is a failure, not "absent".
	 *
	 * A partial deploy, a packaging change or wrong permissions on lib/ is
	 * neither absent nor disabled, and Nextcloud logs nothing for it either, so
	 * unless this records it the AppHost surface fails with nothing in the log.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testAnEnabledOpenRegisterWithoutLibIsLogged(): void {
		$root = sys_get_temp_dir() . '/keepiq-prelude-' . bin2hex(random_bytes(6));
		mkdir($root, 0700);

		try {
			$this->assertFalse(OpenRegisterAutoloader::register($this->appManager(enabled: true, path: $root)));

			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('warning');
			OpenRegisterAutoloader::reportFailure($logger);
		} finally {
			rmdir($root);
		}

	}//end testAnEnabledOpenRegisterWithoutLibIsLogged()

	/**
	 * Disabling OpenRegister after a successful registration is still honoured.
	 *
	 * Under a worker (FrankenPHP on NC 35) statics outlive the request, so the
	 * `$registered` short-circuit must not answer true for an OpenRegister that
	 * has since been disabled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testADisableAfterRegistrationIsHonoured(): void {
		$root = $this->fakeApp();

		try {
			$this->assertTrue(OpenRegisterAutoloader::register($this->appManager(enabled: true, path: $root)));
			$this->assertFalse(OpenRegisterAutoloader::register($this->appManager(enabled: false, path: $root)));
		} finally {
			$this->removeFakeApp($root);
		}

	}//end testADisableAfterRegistrationIsHonoured()

	/**
	 * A failure recorded by the caller is reported like the prelude's own.
	 *
	 * `Application::register()` catches a throwing `AppHost\Bootstrap::register()`
	 * and hands it here, so boot() logs both failure points the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public function testARecordedBootstrapFailureIsLoggedOnce(): void {
		OpenRegisterAutoloader::recordFailure(new \RuntimeException('bootstrap broke'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('AppHost wiring was skipped'),
				$this->callback(static fn (array $ctx): bool => ($ctx['reason'] ?? null) === 'bootstrap broke')
			);

		OpenRegisterAutoloader::reportFailure($logger);
		OpenRegisterAutoloader::reportFailure($logger);

	}//end testARecordedBootstrapFailureIsLoggedOnce()

	/**
	 * An app manager double for register().
	 *
	 * @param bool   $enabled Whether openregister is enabled.
	 * @param string $path    The app path getAppPath() answers.
	 *
	 * @return IAppManager The double.
	 */
	private function appManager(bool $enabled, string $path): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('openregister')->willReturn($enabled);
		$appManager->method('getAppPath')->with('openregister')->willReturn($path);

		return $appManager;

	}//end appManager()

	/**
	 * Create a throwaway app root with a lib/Probe directory.
	 *
	 * @return string The app root.
	 */
	private function fakeApp(): string {
		$root = sys_get_temp_dir() . '/keepiq-prelude-' . bin2hex(random_bytes(6));
		mkdir($root . '/lib/Probe', 0700, true);

		return $root;

	}//end fakeApp()

	/**
	 * Remove an app root created by fakeApp(), whatever it now contains.
	 *
	 * @param string $root The app root.
	 *
	 * @return void
	 */
	private function removeFakeApp(string $root): void {
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($entries as $entry) {
			if ($entry->isDir() === true) {
				rmdir($entry->getPathname());
				continue;
			}

			unlink($entry->getPathname());
		}

		rmdir($root);

	}//end removeFakeApp()

}//end class
