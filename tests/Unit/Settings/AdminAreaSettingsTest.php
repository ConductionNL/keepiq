<?php

/**
 * Unit tests for the five admin area settings classes (admin-scoped-roles §1.1).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Settings
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

namespace OCA\Keepiq\Tests\Unit\Settings;

use OCA\Keepiq\AppInfo\AdminAreaRegistrar;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Settings\AdminAreaSettings;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Name, section, priority, initial state and registration per area.
 */
class AdminAreaSettingsTest extends TestCase {
	/**
	 * Initial state provided, by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $state = [];

	/**
	 * Build one area with recording collaborators.
	 *
	 * @param string $class The area class
	 *
	 * @return AdminAreaSettings
	 */
	private function area(string $class): AdminAreaSettings {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$state = $this->createStub(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, mixed $value): void {
			$this->state[$key] = $value;
		});

		if ($class !== AdminSettings::class) {
			return new $class(l10n: $l10n, initialState: $state);
		}

		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.3.4');
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('0.3.4');
		return new AdminSettings(l10n: $l10n, initialState: $state, appManager: $apps, appConfig: $config);
	}//end area()

	/**
	 * Every area, with its key, name and priority.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:int}>
	 */
	public static function areas(): array {
		return [
			'general' => [AdminSettings::class, 'general', 'General', 10],
			'policies' => [PolicyAdminSettings::class, 'policies', 'Policies', 11],
			'applications' => [ApplicationAdminSettings::class, 'applications', 'Applications and machine access', 12],
			'people' => [PeopleAdminSettings::class, 'people', 'People and offboarding', 13],
			'audit' => [AuditAdminSettings::class, 'audit', 'Audit and compliance', 14],
		];
	}//end areas()

	/**
	 * Each area is delegable, named, in the Keepiq section, ordered, and
	 * provides its own initial-state key and mount element.
	 *
	 * @param string $class The area class
	 * @param string $key The area key
	 * @param string $name The area name
	 * @param int $priority The priority
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	#[DataProvider('areas')]
	public function testAnAreaDescribesItself(string $class, string $key, string $name, int $priority): void {
		$area = $this->area(class: $class);

		$this->assertInstanceOf(IDelegatedSettings::class, $area);
		$this->assertSame($key, $area->getArea());
		$this->assertSame($name, $area->getName());
		$this->assertSame('keepiq', $area->getSection());
		$this->assertSame($priority, $area->getPriority());
		$this->assertSame([], $area->getAuthorizedAppConfig());
		$this->assertSame(AdminAreaAuthorizer::AREAS[$key], $class);

		$form = $area->getForm();
		$this->assertSame('settings/admin', $form->getTemplateName());
		$this->assertSame(['area' => $key], $form->getParams());
		$this->assertTrue($this->state['area-' . $key]);
	}//end testAnAreaDescribesItself()

	/**
	 * Five areas on one page never overwrite each other's state: each key
	 * is provided once, by its own area (POLICY.md #774 note b).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.1
	 */
	public function testFiveAreasOnOnePageKeepFiveKeys(): void {
		foreach (AdminAreaAuthorizer::AREAS as $class) {
			$this->area(class: $class)->getForm();
		}

		foreach (array_keys(AdminAreaAuthorizer::AREAS) as $key) {
			$this->assertTrue($this->state['area-' . $key]);
		}
	}//end testFiveAreasOnOnePageKeepFiveKeys()

	/**
	 * General also carries the version card, and no longer the vault_admin
	 * size: the alias and its notice are gone (#1043).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.3
	 */
	public function testGeneralProvidesTheVersionAndNoVaultAdminSize(): void {
		$this->area(class: AdminSettings::class)->getForm();

		$this->assertSame('0.3.4', $this->state['version']);
		$this->assertTrue($this->state['isUpToDate']);
		$this->assertArrayNotHasKey('vault-admin-members', $this->state);
	}//end testGeneralProvidesTheVersionAndNoVaultAdminSize()

	/**
	 * The registrar binds every area id to an instance of exactly that class,
	 * so `get_class()` on the delegation page names the class a guard names
	 * (red before: AdminSettings resolved to the AppHost generic).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.2
	 */
	public function testTheRegistrarBindsEachAreaToItsOwnClass(): void {
		$factories = [];
		$context = $this->createStub(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);
		(new AdminAreaRegistrar())->register(context: $context);

		$l10n = $this->createStub(IL10N::class);
		$services = [
			IL10N::class => $l10n,
			IInitialState::class => $this->createStub(IInitialState::class),
			IAppManager::class => $this->createStub(IAppManager::class),
			IAppConfig::class => $this->createStub(IAppConfig::class),
		];
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		foreach (AdminAreaAuthorizer::AREAS as $class) {
			$this->assertArrayHasKey($class, $factories);
			$this->assertSame($class, get_class($factories[$class]($container)));
		}
	}//end testTheRegistrarBindsEachAreaToItsOwnClass()

	/**
	 * info.xml registers all five areas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.2
	 */
	public function testInfoXmlRegistersEveryArea(): void {
		// Parse the bytes, not the path: under a Nextcloud bootstrap (CI),
		// lib/base.php blocks libxml's external entity loader, which
		// simplexml_load_file() also uses to open the file, so it returns false
		// (see NextcloudFloorMatrixTest).
		$xml = file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertIsString($xml, 'appinfo/info.xml must be readable');
		$info = simplexml_load_string($xml);
		$this->assertNotFalse($info, 'appinfo/info.xml must parse');
		$admins = array_map('strval', iterator_to_array($info->settings->admin, false));

		$this->assertSame(array_values(AdminAreaAuthorizer::AREAS), $admins);
	}//end testInfoXmlRegistersEveryArea()
}//end class
