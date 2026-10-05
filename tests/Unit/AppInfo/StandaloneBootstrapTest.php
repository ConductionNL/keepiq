<?php

/**
 * Keepiq's bootstrap needs no other app (ADR-006).
 *
 * Runs `Application::register()` against a recording registration context
 * with a recording autoloader in front of every other one. The unit suite has
 * no OpenRegister and no integriq on its autoload path, which is the
 * standalone production state.
 *
 * Limitation: the unit bootstrap loads small OpenRegister MCP and integriq
 * event stubs for other tests. An autoload of those names would not reach the
 * recorder, so this test pins every OTHER name under those prefixes, which
 * includes the whole AppHost engine the bootstrap used to load.
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\AppInfo\McpRegistrar;
use OCA\Keepiq\Mcp\KeepiqScannableServices;
use OCA\Keepiq\Service\SettingsService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for Application::register() without other apps.
 */
final class StandaloneBootstrapTest extends TestCase {
	/**
	 * Foreign names an autoload was attempted for.
	 *
	 * @var string[]
	 */
	private array $foreignAutoloads = [];

	/**
	 * Registration calls, as [method, first argument].
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	private array $calls = [];

	/**
	 * Register the application with a recording context and autoloader.
	 *
	 * @return void
	 */
	private function registerApplication(): void {
		$recorder = function (string $class): void {
			foreach (['OCA\\OpenRegister\\', 'OCA\\Integriq\\'] as $prefix) {
				if (str_starts_with($class, $prefix) === true) {
					$this->foreignAutoloads[] = $class;
				}
			}
		};
		spl_autoload_register($recorder, true, true);

		$context = $this->createMock(IRegistrationContext::class);
		foreach ((new ReflectionClass(IRegistrationContext::class))->getMethods() as $method) {
			$name = $method->getName();
			$context->method($name)->willReturnCallback(
				function (mixed ...$args) use ($name): void {
					$this->calls[] = [$name, $args[0] ?? null, $args[1] ?? null];
				}
			);
		}

		try {
			// App::__construct() needs a Nextcloud server; register() does not.
			$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$application->register($context);
		} finally {
			spl_autoload_unregister($recorder);
		}
	}//end registerApplication()

	/**
	 * The calls of one registration method.
	 *
	 * @param string $method The IRegistrationContext method
	 *
	 * @return array<int,array{0:string,1:mixed,2:mixed}>
	 */
	private function callsOf(string $method): array {
		return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === $method));
	}//end callsOf()

	/**
	 * register() asks for no OpenRegister or integriq class.
	 *
	 * @return void
	 */
	public function testRegisterAutoloadsNoForeignClass(): void {
		$this->registerApplication();

		self::assertSame([], $this->foreignAutoloads);
	}//end testRegisterAutoloadsNoForeignClass()

	/**
	 * Every registrar ran.
	 *
	 * @return void
	 */
	public function testEveryRegistrarRan(): void {
		$this->registerApplication();

		$services = array_column($this->callsOf(method: 'registerService'), 1);
		self::assertContains(SettingsService::class, $services, 'DomainOverrideRegistrar');
		self::assertContains(AdminSettings::class, $services, 'AdminAreaRegistrar');
		self::assertGreaterThanOrEqual(16, count($this->callsOf(method: 'registerEventListener')), 'the three event registrars');
		self::assertCount(1, $this->callsOf(method: 'registerSearchProvider'), 'PlatformIntegrationRegistrar');
		self::assertCount(1, $this->callsOf(method: 'registerNotifierService'), 'PlatformIntegrationRegistrar');
		self::assertCount(3, $this->callsOf(method: 'registerMiddleware'), 'PlatformIntegrationRegistrar');
	}//end testEveryRegistrarRan()

	/**
	 * The MCP alias is registered, as two strings.
	 *
	 * @return void
	 */
	public function testTheMcpAliasIsRegistered(): void {
		$this->registerApplication();

		self::assertSame(
			[['registerServiceAlias', McpRegistrar::ALIAS, KeepiqScannableServices::class]],
			array_values(array_filter($this->callsOf(method: 'registerServiceAlias'), static fn (array $c): bool => $c[1] === McpRegistrar::ALIAS))
		);
	}//end testTheMcpAliasIsRegistered()
}//end class
