<?php

/**
 * The admin API document, the route table and the index agree
 * (admin-public-api §2.2), and every admin route is guarded by one area.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Contract
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

namespace OCA\Keepiq\Tests\Unit\Contract;

use OCA\Keepiq\Controller\AdminIndexController;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\MemberOverviewService;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * One list of v1 admin paths, three places that must agree.
 */
class AdminApiContractTest extends TestCase {
	/**
	 * The prefix every admin API route carries.
	 *
	 * @var string
	 */
	private const PREFIX = '/api/v1/admin';

	/**
	 * The admin routes of appinfo/routes.php, as "METHOD path" => route.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function routes(): array {
		$routes = [];
		foreach ((require __DIR__ . '/../../../appinfo/routes.php')['routes'] as $route) {
			if (str_starts_with((string)$route['url'], self::PREFIX) === true) {
				$routes[$route['verb'] . ' ' . $route['url']] = $route;
			}
		}

		ksort($routes);
		return $routes;
	}//end routes()

	/**
	 * The documented operations, as "METHOD path".
	 *
	 * @return string[]
	 */
	private function documented(): array {
		$document = json_decode((string)file_get_contents(__DIR__ . '/../../../docs/api/admin-v1.openapi.json'), true);
		$operations = [];
		foreach ($document['paths'] as $path => $methods) {
			foreach (array_keys($methods) as $method) {
				$operations[] = strtoupper($method) . ' ' . $path;
			}
		}

		sort($operations);
		return $operations;
	}//end documented()

	/**
	 * The controller method a route reaches.
	 *
	 * @param array<string,mixed> $route The route
	 *
	 * @return ReflectionMethod
	 */
	private function method(array $route): ReflectionMethod {
		[$controller, $action] = explode('#', (string)$route['name']);
		$class = 'OCA\\Keepiq\\Controller\\' . ucfirst($controller) . 'Controller';

		return new ReflectionMethod($class, $action);
	}//end method()

	/**
	 * The OpenAPI document and the routes describe the same operations; the
	 * test names whatever is missing on either side.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#2.2
	 */
	public function testTheDocumentMatchesTheRoutes(): void {
		$routes = array_keys($this->routes());
		$documented = $this->documented();

		$this->assertSame([], array_values(array_diff($routes, $documented)), 'routes missing from docs/api/admin-v1.openapi.json');
		$this->assertSame([], array_values(array_diff($documented, $routes)), 'documented operations without a route');
		$this->assertNotEmpty($routes);
	}//end testTheDocumentMatchesTheRoutes()

	/**
	 * The index lists exactly the routed operations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.1
	 */
	public function testTheIndexListsEveryRoute(): void {
		$listed = array_map(static fn (array $path): string => $path['method'] . ' ' . $path['path'], AdminIndexController::PATHS);
		sort($listed);

		$this->assertSame(array_keys($this->routes()), $listed);
	}//end testTheIndexListsEveryRoute()

	/**
	 * Every admin route except the index is guarded by exactly one area in
	 * the middleware, is never public, needs no fresh password, and the area
	 * in the index matches the guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.6
	 */
	public function testEveryRouteIsGuardedByTheAreaTheIndexNames(): void {
		$areaOf = [];
		foreach (AdminIndexController::PATHS as $path) {
			$areaOf[$path['method'] . ' ' . $path['path']] = $path['area'];
		}

		foreach ($this->routes() as $key => $route) {
			$method = $this->method(route: $route);
			$this->assertSame([], $method->getAttributes(PublicPage::class), $key);
			$this->assertSame([], $method->getAttributes(PasswordConfirmationRequired::class), $key . ' needs a fresh password, which a script cannot give');
			$this->assertStringNotContainsString('forceRevoke', $method->getName());
			$this->assertStringNotContainsString('reinstate', $method->getName());

			if ($key === 'GET ' . self::PREFIX) {
				$this->assertNotSame([], $method->getAttributes(NoAdminRequired::class));
				continue;
			}

			$guards = $method->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $guards, $key);
			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $key . ' must be refused in the middleware');
			$this->assertSame(
				AdminAreaAuthorizer::AREAS[$areaOf[$key]],
				$guards[0]->newInstance()->getSettings(),
				$key . ' is guarded by another area than the index says'
			);
		}
	}//end testEveryRouteIsGuardedByTheAreaTheIndexNames()

	/**
	 * Scenario "Audit token cannot change policies": under Nextcloud's
	 * middleware rule an Audit-only account reaches the audit routes and no
	 * other admin route.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.2
	 */
	public function testAnAuditOnlyAccountReachesOnlyTheAuditRoutes(): void {
		$reached = [];
		foreach ($this->routes() as $key => $route) {
			foreach ($this->method(route: $route)->getAttributes(AuthorizedAdminSetting::class) as $guard) {
				if ($guard->newInstance()->getSettings() === AuditAdminSettings::class) {
					$reached[] = $key;
				}
			}
		}

		$this->assertNotContains('PUT ' . self::PREFIX . '/policies', $reached);
		$this->assertContains('GET ' . self::PREFIX . '/audit', $reached);
		foreach ($reached as $key) {
			$this->assertMatchesRegularExpression('~^[A-Z]+ /api/v1/admin/(audit|compliance|siem)~', $key);
		}
	}//end testAnAuditOnlyAccountReachesOnlyTheAuditRoutes()

	/**
	 * `GET /api/v1/admin/members` (task 1.2): People-guarded in the
	 * middleware, refused to an Audit-only account, documented with the
	 * controller's own query parameters and the member row fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.2
	 */
	public function testTheMembersOperation(): void {
		$key = 'GET ' . self::PREFIX . '/members';
		$routes = $this->routes();
		$this->assertArrayHasKey($key, $routes);

		$method = $this->method(route: $routes[$key]);
		$guards = $method->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $guards);
		$this->assertSame(PeopleAdminSettings::class, $guards[0]->newInstance()->getSettings());
		$this->assertNotSame(AuditAdminSettings::class, $guards[0]->newInstance()->getSettings());

		$document = json_decode((string)file_get_contents(__DIR__ . '/../../../docs/api/admin-v1.openapi.json'), true);
		$operation = $document['paths'][self::PREFIX . '/members']['get'];
		$this->assertSame('people', $operation['x-keepiq-area']);

		$documented = [];
		foreach ($operation['parameters'] as $parameter) {
			if (isset($parameter['in']) === true && $parameter['in'] === 'query') {
				$documented[] = $parameter['name'];
			}
		}

		$this->assertSame(array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()), $documented);

		$row = array_keys($operation['responses']['200']['content']['application/json']['schema']['properties']['results']['items']['properties']);
		$this->assertSame(
			['userId', 'displayName', 'enabled', 'vaultStatus', 'activeSuiteId', 'suiteCreatedAt', 'secretCount', 'teamFolderMemberships', 'hasEmergencyContact'],
			$row
		);
		// The row the service builds carries exactly these keys.
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/MemberOverviewService.php');
		foreach ($row as $field) {
			$this->assertStringContainsString("'" . $field . "' =>", $source, $field . ' is documented but not built');
		}

		$this->assertSame(MemberOverviewService::STATUSES, array_values(array_filter($operation['parameters'][1]['schema']['enum'])));
	}//end testTheMembersOperation()
}//end class
