<?php

/**
 * Refusal tests for the admin area guards (admin-scoped-roles §2.1).
 *
 * Nextcloud's SecurityMiddleware is private API and is not loadable in a
 * pure unit run, so these tests apply its rule to the real route table and
 * the real controller attributes: for a method with
 * `#[AuthorizedAdminSetting]` the request passes when the user is an
 * instance admin or one of their groups is delegated a class the attribute
 * names (server lib/private/AppFramework/Middleware/Security/
 * SecurityMiddleware.php:141-156, `in_array($settingClass,
 * $authorizedClasses, true)`); any other method without
 * `#[NoAdminRequired]` passes for instance admins only. The live check
 * (a real delegated user getting 403) is owed on a test instance.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\AdminAreaSettingsController;
use OCA\Keepiq\Controller\AuditController;
use OCA\Keepiq\Controller\CACertificateController;
use OCA\Keepiq\Controller\EncryptionSuiteController;
use OCA\Keepiq\Controller\MemberOverviewController;
use OCA\Keepiq\Controller\MetricsController;
use OCA\Keepiq\Controller\RecoveryAdminController;
use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which delegate reaches which admin endpoint.
 */
class AdminAreaGuardTest extends TestCase {
	/**
	 * Whether Nextcloud's middleware lets a non-admin user whose groups are
	 * delegated $delegated through to $class::$method.
	 *
	 * @param string $class The controller class
	 * @param string $method The controller method
	 * @param string[] $delegated The delegated settings classes
	 *
	 * @return bool
	 */
	private function admits(string $class, string $method, array $delegated): bool {
		$reflection = new ReflectionMethod($class, $method);
		if ($reflection->getAttributes(NoAdminRequired::class) !== []) {
			return true;
		}

		foreach ($reflection->getAttributes(AuthorizedAdminSetting::class) as $attribute) {
			if (in_array($attribute->newInstance()->getSettings(), $delegated, true) === true) {
				return true;
			}
		}

		return false;
	}//end admits()

	/**
	 * Delegate, endpoint, expected outcome.
	 *
	 * @return array<string,array{0:string[],1:string,2:string,3:bool}>
	 */
	public static function cases(): array {
		$audit = [AuditAdminSettings::class];
		$people = [PeopleAdminSettings::class];
		$general = [AdminSettings::class];
		$apps = [ApplicationAdminSettings::class];
		$policies = [PolicyAdminSettings::class];

		return [
			// Scenario "Auditor cannot change policies".
			'auditor cannot PUT policies' => [$audit, AdminAreaSettingsController::class, 'updatePolicySettings', false],
			'auditor cannot read policies' => [$audit, AdminAreaSettingsController::class, 'getPolicySettings', false],
			'auditor cannot PUT general' => [$audit, AdminAreaSettingsController::class, 'updateGeneralSettings', false],
			'auditor cannot PUT leases' => [$audit, AdminAreaSettingsController::class, 'updateApplicationSettings', false],
			'auditor cannot lower the master password floor' => [$audit, SettingsController::class, 'update', false],
			'auditor cannot force-revoke' => [$audit, EncryptionSuiteController::class, 'forceRevoke', false],
			'auditor cannot renew the CA' => [$audit, CACertificateController::class, 'renewRoot', false],
			'auditor sets audit retention' => [$audit, AdminAreaSettingsController::class, 'updateAuditSettings', true],
			'auditor reads the audit log' => [$audit, AuditController::class, 'index', true],
			// People holder.
			'people force-revokes' => [$people, EncryptionSuiteController::class, 'forceRevoke', true],
			'people reinstates' => [$people, EncryptionSuiteController::class, 'reinstate', true],
			'people cannot PUT policies' => [$people, AdminAreaSettingsController::class, 'updatePolicySettings', false],
			'people cannot read the audit log' => [$people, AuditController::class, 'index', false],
			'people lists members' => [$people, MemberOverviewController::class, 'index', true],
			'auditor cannot list members' => [$audit, MemberOverviewController::class, 'index', false],
			'people manages account recovery' => [$people, RecoveryAdminController::class, 'update', true],
			'auditor cannot manage account recovery' => [$audit, RecoveryAdminController::class, 'update', false],
			// General holder (also the old whole-section delegation).
			'general renews the CA' => [$general, CACertificateController::class, 'renewRoot', true],
			'general scrapes metrics' => [$general, MetricsController::class, 'index', true],
			'auditor cannot scrape metrics' => [$audit, MetricsController::class, 'index', false],
			'general cannot force-revoke' => [$general, EncryptionSuiteController::class, 'forceRevoke', false],
			'general cannot PUT policies' => [$general, AdminAreaSettingsController::class, 'updatePolicySettings', false],
			'general cannot lower the master password floor' => [$general, SettingsController::class, 'update', false],
			// Applications and Policies holders.
			'applications PUT leases' => [$apps, AdminAreaSettingsController::class, 'updateApplicationSettings', true],
			'applications cannot PUT audit' => [$apps, AdminAreaSettingsController::class, 'updateAuditSettings', false],
			'policies PUT policies' => [$policies, AdminAreaSettingsController::class, 'updatePolicySettings', true],
			'policies set the master password floor' => [$policies, SettingsController::class, 'update', true],
			'policies read two-factor gaps' => [$policies, SettingsController::class, 'twoFactorGaps', true],
			'policies cannot PUT general' => [$policies, AdminAreaSettingsController::class, 'updateGeneralSettings', false],
		];
	}//end cases()

	/**
	 * Each delegate reaches its own area's endpoints and no other area's.
	 *
	 * @param string[] $delegated The delegated settings classes
	 * @param string $class The controller class
	 * @param string $method The controller method
	 * @param bool $expected Whether the middleware lets the request through
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.1
	 */
	#[DataProvider('cases')]
	public function testTheMiddlewareAdmitsOnlyTheArea(array $delegated, string $class, string $method, bool $expected): void {
		$this->assertSame($expected, $this->admits(class: $class, method: $method, delegated: $delegated));
	}//end testTheMiddlewareAdmitsOnlyTheArea()

	/**
	 * Every `#[AuthorizedAdminSetting]` in lib/Controller names exactly one
	 * class, and it is one of the five areas (spec "Every Keepiq admin
	 * endpoint is guarded by exactly one area").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.1
	 */
	public function testEveryAdminGuardNamesExactlyOneArea(): void {
		$checked = 0;
		foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') as $file) {
			$class = 'OCA\\Keepiq\\Controller\\' . basename($file, '.php');
			if (class_exists($class) === false) {
				continue;
			}

			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
				if ($attributes === []) {
					continue;
				}

				$checked++;
				$this->assertCount(1, $attributes, $class . '::' . $method->getName());
				$this->assertContains(
					$attributes[0]->newInstance()->getSettings(),
					AdminAreaAuthorizer::AREAS,
					$class . '::' . $method->getName() . ' must name one of the five areas'
				);
			}
		}

		$this->assertGreaterThan(20, $checked);
	}//end testEveryAdminGuardNamesExactlyOneArea()

	/**
	 * The settings routes: one GET and one PUT per settings area, each on a
	 * method guarded by that area, and the combined route is gone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.1
	 */
	public function testEachAreaRouteIsGuardedByItsArea(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$byUrl = [];
		foreach ($routes as $route) {
			$byUrl[$route['verb'] . ' ' . $route['url']] = $route['name'];
		}

		$this->assertArrayNotHasKey('PUT /api/settings/admin', $byUrl);
		$this->assertArrayNotHasKey('GET /api/settings/admin', $byUrl);

		$expected = [
			'general' => AdminSettings::class,
			'policies' => PolicyAdminSettings::class,
			'applications' => ApplicationAdminSettings::class,
			'audit' => AuditAdminSettings::class,
		];
		foreach ($expected as $area => $areaClass) {
			foreach (['GET', 'PUT'] as $verb) {
				$name = $byUrl[$verb . ' /api/settings/admin/' . $area] ?? null;
				$this->assertNotNull($name, $verb . ' /api/settings/admin/' . $area);
				[$controller, $method] = explode('#', $name);
				$this->assertSame('adminAreaSettings', $controller);
				$this->assertTrue($this->admits(class: AdminAreaSettingsController::class, method: $method, delegated: [$areaClass]));
				foreach ($expected as $other) {
					if ($other !== $areaClass) {
						$this->assertFalse($this->admits(class: AdminAreaSettingsController::class, method: $method, delegated: [$other]));
					}
				}
			}
		}
	}//end testEachAreaRouteIsGuardedByItsArea()
}//end class
