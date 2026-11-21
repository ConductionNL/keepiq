<?php

/**
 * Every hand-written service factory builds its service.
 *
 * The registrars spell out constructor arguments by name. A factory and the
 * constructor it calls can drift apart (a parameter renamed or removed on one
 * side only), and PHP reports that only when the factory runs, which in
 * production is `occ upgrade` or the first request. This test runs every
 * factory `Application::register()` registers against a container that hands
 * out test doubles, so drift fails here instead.
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
use OCA\Keepiq\Repair\InitializeSettings;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * Runs each registered service factory once.
 */
final class ServiceFactoryWiringTest extends TestCase {
	/**
	 * The service factories Application::register() registers, by service name.
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	public static function factories(): array {
		$factories = [];
		$context = (new self('factories'))->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = [$name, $factory];
			}
		);

		// App::__construct() needs a Nextcloud server; register() does not.
		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$application->register($context);

		return $factories;
	}//end factories()

	/**
	 * The factories this test exists for are among the ones it runs.
	 *
	 * @return void
	 */
	public function testTheSettingsStackFactoriesAreCovered(): void {
		$names = array_keys(self::factories());

		self::assertContains(needle: SettingsService::class, haystack: $names);
		self::assertContains(needle: InitializeSettings::class, haystack: $names);
	}//end testTheSettingsStackFactoriesAreCovered()

	/**
	 * A factory builds an instance of the service it is registered for.
	 *
	 * @param string   $name    The registered service name
	 * @param callable $factory The registered factory
	 *
	 * @return void
	 */
	#[DataProvider('factories')]
	public function testFactoryBuildsItsService(string $name, callable $factory): void {
		$service = $factory($this->container());

		if (class_exists($name) === true || interface_exists($name) === true) {
			self::assertInstanceOf(expected: $name, actual: $service);
			return;
		}

		self::assertIsObject(actual: $service);
	}//end testFactoryBuildsItsService()

	/**
	 * A container that answers every class or interface name with a test double.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				if (interface_exists($id) === false && class_exists($id) === false) {
					return $id;
				}

				$class = new ReflectionClass($id);
				if ($class->isFinal() === true || $class->isEnum() === true) {
					return $class->newInstanceWithoutConstructor();
				}

				return $this->createMock(originalClassName: $id);
			}
		);

		return $container;
	}//end container()
}//end class
