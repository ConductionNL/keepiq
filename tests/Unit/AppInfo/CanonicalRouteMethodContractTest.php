<?php

/**
 * Tests for the canonical AppHost route table's method contract.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
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
 * The canonical AppHost route table routes a fixed set of names into THIS
 * app's controller namespace, and OpenRegister only substitutes its generic
 * controller when this app does not ship a class of that name.
 *
 * `OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * registers the DI alias `OCA\Keepiq\Controller\XController` ->
 * `OCA\OpenRegister\AppHost\Controller\GenericXController` ONLY when the leaf
 * class does not exist. So the seam has two sides, and they fail differently:
 *
 *   - Leaf does NOT ship the class  -> the alias binds and the generic serves
 *     every canonical method. Nothing is owed. (This is why the absence of
 *     HealthController / MetricsController / PreferencesController on disk is
 *     correct and must not be "fixed" by creating them.)
 *   - Leaf DOES ship the class      -> the alias is skipped and the generic is
 *     never constructed, so the leaf owes EVERY method the canonical table
 *     routes to that controller. A missing one is not a 404: the router
 *     matches the URL, the dispatcher reflects the method, and the request
 *     dies with a 500 ReflectionException.
 *
 * Measured live on 2026-08-08 against the dev instance:
 *
 *     GET /apps/keepiq/api/settings -> 200
 *     PUT /apps/keepiq/api/settings -> 500
 *     nextcloud.log: ReflectionException: Method
 *     OCA\Keepiq\Controller\SettingsController::update() does not exist
 *     (route keepiq.settings.update)
 *
 * This test asserts the ITEM (each individual method), never the container
 * (the controller class merely existing).
 */
class CanonicalRouteMethodContractTest extends TestCase {

	/**
	 * The canonical route names supplied by `\OCA\OpenRegister\AppHost\Routes`.
	 *
	 * Pinned here rather than read from the OpenRegister class because the unit
	 * suite runs against the `nextcloud/ocp` stubs with no OpenRegister on the
	 * autoload path. `testRoutesFileStillDelegatesToTheCanonicalTable()` below
	 * is the precondition guard: this list is only owed while
	 * `appinfo/routes.php` returns `Routes::standard(...)`.
	 *
	 * Keyed `controllerPrefix => [method, ...]`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CANONICAL_ROUTES = [
		'Dashboard' => ['page', 'catchAll'],
		'Settings' => ['index', 'create', 'update', 'load'],
		'Preferences' => ['getPreference', 'setPreference'],
		'Metrics' => ['index'],
		'Health' => ['index'],
	];

	/**
	 * Reflect the canonical methods a leaf-owned controller fails to implement.
	 *
	 * Shared by the contract assertion and by its negative control, so the
	 * control exercises the exact code path the real assertion trusts.
	 *
	 * @param array<string, array<int, string>> $expected Prefix => method names.
	 * @param int $inspected Out-param: methods actually reflected.
	 *
	 * @return array<int, string> Fully-qualified `Prefix::method()` names that are missing.
	 */
	private function findMissingCanonicalMethods(array $expected, int &$inspected): array {
		$inspected = 0;
		$missing = [];

		foreach ($expected as $prefix => $methods) {
			// The class file existing ON DISK is what makes the AppHost skip
			// the alias. `class_exists()` alone would also be satisfied by the
			// DI alias target inside a booted container, which is precisely the
			// case this test must NOT treat as leaf-owned.
			$file = __DIR__ . '/../../../lib/Controller/' . $prefix . 'Controller.php';
			if (file_exists($file) === false) {
				continue;
			}

			$class = 'OCA\\Keepiq\\Controller\\' . $prefix . 'Controller';
			$this->assertTrue(
				class_exists($class),
				sprintf('%s exists on disk but does not autoload as %s', $file, $class)
			);

			$reflection = new ReflectionClass($class);

			foreach ($methods as $method) {
				$inspected++;
				if ($reflection->hasMethod($method) === false) {
					$missing[] = $prefix . 'Controller::' . $method . '()';
					continue;
				}

				$this->assertTrue(
					$reflection->getMethod($method)->isPublic(),
					sprintf('%s::%s() must be public to be dispatchable', $class, $method)
				);
			}
		}//end foreach

		return $missing;
	}//end findMissingCanonicalMethods()

	/**
	 * Precondition: the app still adopts the canonical AppHost route table.
	 *
	 * Everything below is owed only because `appinfo/routes.php` returns
	 * `Routes::standard(...)` verbatim — that call is what puts
	 * `PUT /api/settings -> settings#update` on this app's router. If the app
	 * ever stopped delegating, the pinned list would be stale rather than
	 * authoritative, and a green from the contract test would mean nothing.
	 *
	 * @return void
	 */
	public function testRoutesFileStillDelegatesToTheCanonicalTable(): void {
		$routesSource = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');

		$this->assertIsString($routesSource, 'appinfo/routes.php must be readable');
		$this->assertStringContainsString(
			'AppHost\\Routes::standard(',
			$routesSource,
			'appinfo/routes.php no longer returns the canonical AppHost route table, so '
			. 'CANONICAL_ROUTES is no longer the authority on what this app must implement.'
		);
	}//end testRoutesFileStillDelegatesToTheCanonicalTable()

	/**
	 * A controller this app ships itself must implement every canonical
	 * method routed to it — the AppHost generic will not fill the gap.
	 *
	 * @return void
	 */
	public function testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem(): void {
		$inspected = 0;
		$missing = $this->findMissingCanonicalMethods(self::CANONICAL_ROUTES, $inspected);

		// Positive control: an empty finding list is only meaningful if
		// something was actually inspected. Zero inspections would mean the
		// lib/Controller path probe silently matched nothing.
		$this->assertGreaterThan(
			0,
			$inspected,
			'No leaf-owned canonical controller method was inspected — the lib/Controller '
			. 'path probe is broken, so the empty finding list means nothing.'
		);

		$this->assertSame(
			[],
			$missing,
			sprintf(
				'The canonical AppHost route table routes to these method(s), but this app '
				. 'ships the controller itself so no generic is aliased in. Each of these is '
				. "a 500, not a 404.\n  - %s",
				implode("\n  - ", $missing)
			)
		);
	}//end testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem()

	/**
	 * Negative control: the scan must be able to REPORT a missing method.
	 *
	 * A checker that can only return an empty list is indistinguishable from a
	 * passing one. Feeding the same helper a method name that certainly does
	 * not exist proves the green above is a measurement, not a no-op.
	 *
	 * @return void
	 */
	public function testTheScanActuallyReportsAMissingMethod(): void {
		$inspected = 0;
		$missing = $this->findMissingCanonicalMethods(
			['Settings' => ['thisCanonicalMethodDoesNotExist']],
			$inspected
		);

		$this->assertSame(1, $inspected, 'The negative control must inspect exactly one method');
		$this->assertSame(
			['SettingsController::thisCanonicalMethodDoesNotExist()'],
			$missing,
			'The scan failed to report a method that definitely does not exist, so its '
			. 'empty findings elsewhere carry no information.'
		);
	}//end testTheScanActuallyReportsAMissingMethod()

}//end class
