<?php

/**
 * Keepiq OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader so this app can reference
 * `OCA\OpenRegister\AppHost\…` from its own `Application::register()`.
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\AppInfo;

use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Registers OpenRegister's autoload prefix before AppHost is referenced.
 *
 * ## Why this is needed (ADR-040)
 *
 * The enabled apps are walked in SORTED order:
 * `Coordinator::registerApps()` registers one app's autoloader and then calls
 * that app's `register()`, before moving to the next. (The autoloader call is
 * `OC_App::registerAutoloading()` up to Nextcloud 34 and
 * `AppManager::registerAutoloading()` from 35; both are private, and the 34 one
 * no longer exists.) So every app's `register()` runs BEFORE the PSR-4 prefix
 * of every alphabetically-LATER app exists.
 *
 * `keepiq` sorts before `openregister`, so `OCA\OpenRegister\` is NOT
 * autoloadable inside `Application::register()` on a perfectly healthy
 * instance with OpenRegister enabled. Left unguarded, the resulting `\Error`
 * aborted the whole of `register()` — the audit listener recorded ZERO
 * dispatched events, while `Coordinator` logged an `emergency` and carried on,
 * so the app stayed enabled and looked fine.
 *
 * Lives in its own class rather than inline in `Application::register()` for
 * one reason: `Application` cannot be constructed without a Nextcloud DI
 * container, so an inline prelude is unreachable from a unit test. Here the
 * degraded-path contract — "this NEVER throws, whatever the instance looks
 * like" — is directly assertable, and it is asserted.
 *
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
 */
final class OpenRegisterAutoloader {

	/**
	 * The app whose autoload prefix this prelude registers.
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The PSR-4 prefix this prelude resolves, trailing separator included.
	 */
	private const OPENREGISTER_NAMESPACE = 'OCA\\OpenRegister\\';

	/**
	 * Whether the prefix is already on the autoloader.
	 *
	 * `spl_autoload_register()` has no early-return of its own, so without this
	 * every leaf calling the prelude would stack another closure on the loader
	 * for the life of the request.
	 *
	 * @var boolean
	 */
	private static bool $registered = false;

	/**
	 * The registered autoload callable, kept so it can be removed again.
	 *
	 * Registering an autoloader is a PROCESS-WIDE side effect. In a unit-test
	 * run that outlives one test: every later `class_exists()` for an absent
	 * class runs this closure, and PHPUnit's strict coverage metadata then
	 * reports unrelated tests as risky for "executing code not listed as
	 * covered or used" — which is true, and is the suite telling us a global
	 * was left behind. Measured on keepiq#712 (ConnectionReporterTest). The
	 * handle lets {@see unregister()} put the process back as it found it.
	 *
	 * @var callable|null
	 */
	private static $loader = null;

	/**
	 * Why the last register() call returned false, when it was not a clean "absent".
	 *
	 * `register()` runs before any logger can be injected and must never
	 * throw, so it cannot report a failure itself. It records it here and
	 * {@see reportFailure()} logs it from `Application::boot()`. Without this,
	 * every failure collapsed into an unlogged false — which is how the
	 * Nextcloud 35 removal of `OC_App::registerAutoloading()` turned into
	 * AppHost 500s with nothing in the log.
	 *
	 * @var \Throwable|null
	 */
	private static ?\Throwable $failure = null;

	/**
	 * Register OpenRegister's PSR-4 prefix on the composer autoloader.
	 *
	 * MUST be called before any `OCA\OpenRegister\…` reference in
	 * `Application::register()`, including a `class_exists()` probe — the probe
	 * answers FALSE, not "not yet loaded", and a FALSE is indistinguishable
	 * from OpenRegister being absent.
	 *
	 * ## No private API, and no booting OpenRegister
	 *
	 * This used to call `\OC_App::registerAutoloading()`. That is private API —
	 * `lib/private/legacy/OC_App.php` — and **Nextcloud 35 removed the method**.
	 * The `\Error` landed in the catch below, this returned false, the caller's
	 * `class_exists()` probe answered false, and the AppHost plumbing was
	 * silently skipped: `/api/health` and `/api/metrics` returned 500 with an
	 * HTML error page on a healthy instance (keepiq#712, nine Newman
	 * assertions). NC 35 moved the method to `OC\App\AppManager`, which is also
	 * private — it is not on `OCP\App\IAppManager` — so porting it would buy
	 * one version and re-arm the same trap.
	 *
	 * `IAppManager::loadApp('openregister')` IS public, and is still not used
	 * here. It calls `Coordinator::bootApp()`, which would boot OpenRegister
	 * before its own `register()` has run — and `bootApp()` sets
	 * `bootedApps[..] = true` BEFORE booting, so a throw there is caught, logged
	 * once, and OpenRegister is never booted again for that request. Its
	 * `boot()` dispatches the deep-link registration event and boots the
	 * integration providers, leaf registry, object-source providers and
	 * federation. Trading this app's 500 for OpenRegister silently losing half
	 * its boot, instance-wide, is not a trade worth making.
	 *
	 * So this does what Nextcloud does, with public API and plain PHP. For an
	 * app shipping `vendor/autoload.php` rather than `composer/autoload.php` —
	 * which is OpenRegister — `AppManager::registerAutoloading()` reduces to
	 *
	 *     addPsr4('OCA\\OpenRegister\\', $path . '/lib/', true);
	 *
	 * a PSR-4 prefix pointing at `lib/`, and nothing else. `spl_autoload_register`
	 * expresses exactly that. The path comes from `IAppManager::getAppPath()`,
	 * which is public and correct across multiple `apps_paths` — the reason a
	 * hardcoded `__DIR__ . '/../../../openregister'` is not acceptable here.
	 *
	 * OpenRegister's own `vendor/autoload.php` is deliberately NOT required:
	 * that would pull its entire third-party dependency tree into this app's
	 * process, where a version differing from ours would win on a first-come
	 * basis. Nextcloud does not do that for this app either. Class-level type
	 * hints are not resolved until used, so a PSR-4 prefix over `lib/` is
	 * sufficient to reference `AppHost\Bootstrap`.
	 *
	 * `getAppPath()` is a pure path lookup and does not consult enabled state,
	 * so enabled state is checked first: Nextcloud only autoloads enabled apps,
	 * and an admin who disables OpenRegister must not still get its code loaded
	 * into this app's process.
	 *
	 * @param IAppManager|null $appManager The app manager; resolved from the
	 *                                     server container when null. Injectable
	 *                                     for tests only.
	 *
	 * @return bool True when the prefix is registered, false when OpenRegister
	 *              is absent, disabled, or otherwise unresolvable — in which
	 *              case the caller MUST fall through to its degraded path.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the public
	 * service locator, and this runs at the composition root — there is no
	 * container to inject, which is the whole reason a prelude exists.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public static function register(?IAppManager $appManager = null): bool {
		self::$failure = null;

		try {
			$appManager ??= \OCP\Server::get(IAppManager::class);

			// Checked BEFORE the short-circuit: under a worker (FrankenPHP on
			// NC 35) this static outlives the request, and an OpenRegister
			// disabled since the first registration must not still be wired.
			if ($appManager->isEnabledForAnyone(self::OPENREGISTER_APP_ID) === false) {
				// Absent or disabled: the expected degraded path, and quiet.
				return false;
			}

			if (self::$registered === true) {
				return true;
			}

			$path = rtrim($appManager->getAppPath(self::OPENREGISTER_APP_ID), '/');

			// Enabled but without lib/ (a partial deploy, a packaging change,
			// wrong permissions) is neither absent nor disabled, and Nextcloud
			// logs nothing for it either. Registering a prefix over nothing would
			// only hide it, so record it for reportFailure() instead.
			if (is_dir($path . '/lib') === false) {
				self::$failure = new RuntimeException(
					sprintf('OpenRegister is enabled but %s/lib is not a directory', $path)
				);
				return false;
			}

			self::$loader = static function (string $class) use ($path): void {
				self::loadClass(appPath: $path, class: $class);
			};

			spl_autoload_register(self::$loader);

			self::$registered = true;
			return true;
		} catch (\Throwable $e) {
			// OpenRegister enabled but not on disk, or the server container is
			// not up (unit tests), or something unexpected. The caller then skips
			// the AppHost plumbing. Never rethrow: an exception
			// escaping here would abort the caller's entire register(), which is
			// the exact defect this prelude exists to prevent. Record it instead,
			// for reportFailure() to log once a logger is available.
			self::$failure = $e;
			return false;
		}

	}//end register()

	/**
	 * Remove the autoloader again, for tests that must not leak it.
	 *
	 * Production never calls this: the prefix is wanted for the life of the
	 * request. A test suite runs many tests in one process, so a test that
	 * exercises {@see register()} has to hand the process back unchanged or it
	 * changes the behaviour of every test after it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public static function unregister(): void {
		if (self::$loader !== null) {
			spl_autoload_unregister(self::$loader);
			self::$loader = null;
		}

		self::$registered = false;
		self::$failure = null;

	}//end unregister()

	/**
	 * Record a failure of the AppHost wiring that follows this prelude.
	 *
	 * `Application::register()` catches a throwing `AppHost\Bootstrap::register()`
	 * for the same reason this class catches its own failures, and cannot log
	 * there either. Handing it here lets {@see reportFailure()} cover both.
	 *
	 * @param \Throwable $failure What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public static function recordFailure(\Throwable $failure): void {
		self::$failure = $failure;

	}//end recordFailure()

	/**
	 * Log why the AppHost wiring fell through to the degraded path.
	 *
	 * Called from `Application::boot()`, where the logger is resolvable. A
	 * disabled or absent OpenRegister records nothing and stays quiet, and so
	 * does one that is enabled but not on disk ({@see AppPathNotFoundException}),
	 * which Nextcloud's Coordinator already logs. Anything else leaves one
	 * warning. The failure is cleared once reported, so it is logged once per
	 * request: a persistent failure logs on every request until it is fixed.
	 *
	 * @param LoggerInterface $logger The logger to report to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	public static function reportFailure(LoggerInterface $logger): void {
		$failure = self::$failure;
		self::$failure = null;

		if ($failure === null || $failure instanceof AppPathNotFoundException) {
			return;
		}

		$logger->warning(
			'OpenRegister AppHost wiring was skipped: {reason}',
			['reason' => $failure->getMessage(), 'exception' => $failure]
		);

	}//end reportFailure()

	/**
	 * Resolve and include one class, if it is ours and present on disk.
	 *
	 * The body of the registered closure, lifted out so it is reachable from a
	 * test. Inside the closure it could only ever run when PHP happened to
	 * autoload an `OCA\OpenRegister\…` name during the suite — which no test
	 * can arrange and which therefore went unexercised, while being the part
	 * that actually does the work.
	 *
	 * Silent on a miss, deliberately: an autoloader is asked about every class
	 * PHP cannot already see, most of which belong to somebody else. Throwing,
	 * or even warning, would make this app noisy about other people's lookups.
	 *
	 * @param string $appPath Absolute path to the openregister app.
	 * @param string $class   The class being resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	private static function loadClass(string $appPath, string $class): void {
		$file = self::classFile(appPath: $appPath, class: $class);
		if ($file === null) {
			return;
		}

		if (is_file($file) === true) {
			require_once $file;
		}

	}//end loadClass()

	/**
	 * The file a PSR-4 class name maps to, or null when it is not ours.
	 *
	 * Split out of the closure so the mapping is directly assertable. It is the
	 * part of this class Nextcloud used to own — `addPsr4()` did it — and now
	 * does not, so it is the part most worth pinning: a missing trailing
	 * separator, an off-by-one in the slice, or forgetting that PHP namespace
	 * separators are backslashes while paths are not, each produce an autoloader
	 * that silently resolves nothing. And "resolves nothing" is
	 * indistinguishable from "OpenRegister is absent" at the call site.
	 *
	 * Returning null rather than a path for a foreign class matters: an
	 * autoloader that answers for names it does not own can shadow another
	 * loader that would have resolved them.
	 *
	 * @param string $appPath Absolute path to the openregister app, no trailing slash.
	 * @param string $class   The fully qualified class name being resolved.
	 *
	 * @return string|null The candidate file, or null when the class is not OpenRegister's.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md#requirement-apphost-prelude-registers-openregister-with-public-api-only
	 */
	private static function classFile(string $appPath, string $class): ?string {
		$prefix = self::OPENREGISTER_NAMESPACE;
		if (str_starts_with($class, $prefix) === false) {
			return null;
		}

		$relative = substr($class, strlen($prefix));
		if ($relative === '') {
			return null;
		}

		return $appPath . '/lib/' . str_replace('\\', '/', $relative) . '.php';

	}//end classFile()
}//end class
