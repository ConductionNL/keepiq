<?php

/**
 * Keepiq's own route table (ADR-006).
 *
 * Nextcloud's router requires every enabled app's routes.php on a route-cache
 * miss, and a throw there answers HTTP 500 on every page of the instance
 * (#857, #867). Keepiq's table is therefore one static array with no branch on
 * another app. The unit suite runs with no OpenRegister on the autoload path,
 * which is the standalone production state.
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
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for appinfo/routes.php.
 */
class StaticRouteTableTest extends TestCase {

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
	 * @return void
	 */
	public function testOpenRegisterIsAbsentInThisSuite(): void {
		$this->assertFalse(class_exists('OCA\OpenRegister\AppHost\Routes'));
	}//end testOpenRegisterIsAbsentInThisSuite()

	/**
	 * The table carries the shell routes and none of the removed ones.
	 *
	 * @return void
	 */
	public function testTheShellRoutesArePresentAndTheRemovedOnesAreNot(): void {
		$names = array_column($this->loadRoutes()['routes'], 'name');

		foreach (['dashboard#page', 'settings#index', 'settings#create', 'settings#update', 'preferences#getPreference', 'preferences#setPreference', 'health#index', 'metrics#index', 'secret#index'] as $expected) {
			$this->assertContains($expected, $names, $expected);
		}

		$this->assertNotContains('settings#load', $names);
		$this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'store#'))));
	}//end testTheShellRoutesArePresentAndTheRemovedOnesAreNot()

	/**
	 * Every name registers once, and the SPA catch-all is last.
	 *
	 * Nextcloud names a route by `name` plus its optional `postfix`
	 * (RouteParser), so two URLs may reach one method with distinct postfixes.
	 *
	 * @return void
	 */
	public function testNamesAreUniqueAndTheCatchAllIsLast(): void {
		$routes = $this->loadRoutes()['routes'];
		$registered = array_map(static fn (array $route): string => $route['name'] . ($route['postfix'] ?? ''), $routes);

		$this->assertSame(count($registered), count(array_unique($registered)), 'Every route name registers once.');
		$this->assertSame('dashboard#catchAll', end($routes)['name']);
	}//end testNamesAreUniqueAndTheCatchAllIsLast()

	/**
	 * Every route resolves to a public method on a Keepiq controller.
	 *
	 * @return void
	 */
	public function testEveryRouteResolvesToAPublicKeepiqMethod(): void {
		$missing = [];
		$routes = $this->loadRoutes()['routes'];
		foreach ($routes as $route) {
			[$controller, $method] = explode('#', (string)$route['name']);
			$class = 'OCA\\Keepiq\\Controller\\' . ucfirst($controller) . 'Controller';
			if (class_exists($class) === false
				|| (new ReflectionClass($class))->hasMethod($method) === false
				|| (new ReflectionClass($class))->getMethod($method)->isPublic() === false
			) {
				$missing[] = $route['name'];
			}
		}

		$this->assertGreaterThan(100, count($routes));
		$this->assertSame([], $missing);
	}//end testEveryRouteResolvesToAPublicKeepiqMethod()

	/**
	 * The table does not change when an OpenRegister AppHost class exists.
	 *
	 * Runs in its own process, because it defines a stand-in for the AppHost
	 * class that must not leak into the tests above.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState  disabled
	 *
	 * @return void
	 */
	public function testTheTableIsTheSameWithOpenRegisterPresent(): void {
		$without = $this->loadRoutes();
		eval(
			'namespace OCA\OpenRegister\AppHost; class Routes { '
			. 'public static function standard(array $extra = []): array { '
			. "return ['routes' => \$extra, 'delegated' => true]; } }"
		);

		$this->assertSame($without, $this->loadRoutes());
	}//end testTheTableIsTheSameWithOpenRegisterPresent()
}//end class
