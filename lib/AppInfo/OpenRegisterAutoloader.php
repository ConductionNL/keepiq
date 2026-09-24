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
 * @spec openspec/specs/apphost-adoption/spec.md
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
	 * @return bool True when the prefix is registered, false when OpenRegister
	 *              is absent, disabled, or otherwise unresolvable — in which
	 *              case the caller MUST fall through to its degraded path.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public static function register(): bool {
		if (self::$registered === true) {
			return true;
		}

		try {
			$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
			$path = rtrim($appManager->getAppPath(self::OPENREGISTER_APP_ID), '/');

			// A missing lib/ means this is not an app we can autoload from, and
			// registering a prefix pointing at nothing would turn a clean
			// "absent" into class_exists() answering false for a reason nobody
			// can see.
			if (is_dir($path . '/lib') === false) {
				return false;
			}

			spl_autoload_register(
				static function (string $class) use ($path): void {
					$file = self::classFile(appPath: $path, class: $class);
					if ($file !== null && is_file($file) === true) {
						require_once $file;
					}
				}
			);

			self::$registered = true;
			return true;
		} catch (\Throwable) {
			// OpenRegister absent, disabled, or the server container is not up
			// (unit tests). The caller's class_exists() guard then skips the
			// AppHost plumbing. Never rethrow: an exception escaping here would
			// abort the caller's entire register(), which is the exact defect
			// this prelude exists to prevent.
			return false;
		}

	}//end register()

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
	 * @spec openspec/specs/apphost-adoption/spec.md
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
