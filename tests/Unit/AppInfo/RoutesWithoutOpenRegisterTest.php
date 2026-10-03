<?php

/**
 * Tests that appinfo/routes.php loads when OpenRegister is missing or disabled.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Nextcloud's router requires every enabled app's routes.php on a route-cache
 * miss. When that file throws, every page of the instance answers HTTP 500,
 * the login page and the apps page included (#857 not installed, #867
 * installed but disabled).
 *
 * The unit suite runs with no OpenRegister on the autoload path, which is
 * exactly the production state of both issues, so requiring the real file
 * here reproduces them. On the old unguarded file this test died with
 * `Class "OCA\OpenRegister\AppHost\Routes" not found`.
 */
class RoutesWithoutOpenRegisterTest extends TestCase {

	/**
	 * Load the real route file.
	 *
	 * @return array{routes: array<int, array<string, mixed>>}
	 */
	private function loadRoutes(): array {
		return require __DIR__ . '/../../../appinfo/routes.php';
	}//end loadRoutes()

	/**
	 * Precondition: this process really has no OpenRegister to load.
	 *
	 * Without it the tests below would exercise the preferred path and prove
	 * nothing about the fallback.
	 *
	 * @return void
	 */
	public function testOpenRegisterIsAbsentInThisSuite(): void {
		$this->assertFalse(class_exists('OCA\OpenRegister\AppHost\Routes'));
	}//end testOpenRegisterIsAbsentInThisSuite()

	/**
	 * The file returns a route table instead of throwing.
	 *
	 * @return void
	 */
	public function testRoutesFileLoadsWithoutOpenRegister(): void {
		$routes = $this->loadRoutes();

		$this->assertArrayHasKey('routes', $routes);
		$names = array_column($routes['routes'], 'name');

		// The app page, a domain route and the SPA catch-all, which is last.
		$this->assertContains('dashboard#page', $names);
		$this->assertContains('secret#index', $names);
		$this->assertSame('dashboard#catchAll', end($names));
		// Nextcloud names a route by `name` plus its optional `postfix`
		// (RouteParser), so two URLs may reach one method with distinct postfixes.
		$registered = array_map(static fn (array $route): string => $route['name'] . ($route['postfix'] ?? ''), $routes['routes']);
		$this->assertSame(count($registered), count(array_unique($registered)), 'Every route name registers once.');
	}//end testRoutesFileLoadsWithoutOpenRegister()

	/**
	 * Every fallback route points at a method Keepiq ships itself.
	 *
	 * The AppHost-only controllers (preferences, health, metrics) exist only
	 * as aliases to OpenRegister classes. Routing to them without OpenRegister
	 * would trade the instance-wide 500 for a per-route one.
	 *
	 * @return void
	 */
	public function testEveryFallbackRouteResolvesToALeafMethod(): void {
		$missing = [];
		$routes = $this->loadRoutes()['routes'];
		foreach ($routes as $route) {
			[$controller, $method] = explode('#', (string) $route['name']);
			$class = 'OCA\\Keepiq\\Controller\\' . ucfirst($controller) . 'Controller';
			if (class_exists($class) === false
				|| (new ReflectionClass($class))->hasMethod($method) === false
			) {
				$missing[] = $route['name'];
			}
		}

		$this->assertGreaterThan(5, count($routes));
		$this->assertSame([], $missing);
	}//end testEveryFallbackRouteResolvesToALeafMethod()

	/**
	 * With OpenRegister present the file still delegates to the AppHost table.
	 *
	 * Runs in its own process, because it defines a stand-in for the AppHost
	 * class that must not leak into the absent-OpenRegister tests above.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState  disabled
	 *
	 * @return void
	 */
	public function testRoutesFileDelegatesWhenOpenRegisterIsPresent(): void {
		eval(
			'namespace OCA\OpenRegister\AppHost; class Routes { '
			. 'public static function standard(array $extra = []): array { '
			. "return ['routes' => \$extra, 'delegated' => true]; } }"
		);

		$routes = $this->loadRoutes();

		$this->assertTrue($routes['delegated'] ?? false);
		$this->assertNotContains('dashboard#catchAll', array_column($routes['routes'], 'name'));
	}//end testRoutesFileDelegatesWhenOpenRegisterIsPresent()
}//end class
