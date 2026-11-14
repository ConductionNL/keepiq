<?php

/**
 * ContractBindings: every kernel contract is aliased once, to a concrete
 * class that implements it.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use FilesystemIterator;
use OCA\Keepiq\Service\ContractBindings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The contract bindings.
 */
class ContractBindingsTest extends TestCase {
	/**
	 * The aliases registered, as [contract, implementation] pairs.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function aliases(): array {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);
		(new ContractBindings())->register(context: $context);
		return $aliases;
	}//end aliases()

	/**
	 * Every alias points a kernel contract at a concrete class that implements it.
	 *
	 * @return void
	 */
	public function testEveryAliasPointsAtAnImplementation(): void {
		$aliases = $this->aliases();
		$this->assertGreaterThan(expected: 80, actual: count($aliases));
		foreach ($aliases as [$contract, $implementation]) {
			$this->assertTrue(condition: interface_exists($contract), message: $contract.' is not an interface');
			$this->assertStringStartsWith(prefix: 'OCA\\Keepiq\\Kernel\\Contracts\\', string: $contract);
			$this->assertTrue(condition: class_exists($implementation), message: $implementation.' does not exist');
			$class = new ReflectionClass(objectOrClass: $implementation);
			$this->assertFalse(condition: $class->isAbstract(), message: $implementation.' is abstract');
			$this->assertTrue(condition: $class->implementsInterface($contract), message: $implementation.' does not implement '.$contract);
		}
	}//end testEveryAliasPointsAtAnImplementation()

	/**
	 * No contract is aliased twice, so a later alias can never silently replace an earlier one.
	 *
	 * @return void
	 */
	public function testNoContractIsAliasedTwice(): void {
		$counts = array_filter(array_count_values(array_column($this->aliases(), 0)), static fn (int $count): bool => $count > 1);
		$this->assertSame(expected: [], actual: $counts);
	}//end testNoContractIsAliasedTwice()

	/**
	 * Every contract a concrete service implements is aliased, so the container can resolve it.
	 *
	 * @return void
	 */
	public function testEveryImplementedContractIsAliased(): void {
		$aliased = array_flip(array_column($this->aliases(), 0));
		$lib     = dirname(__DIR__, 3).'/lib';
		$missing = [];
		$seen    = 0;
		$files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib.'/Service', FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$class = 'OCA\\Keepiq\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($lib) + 1));
			if ($file->getExtension() !== 'php' || $this->loads(class: $class) === false) {
				continue;
			}

			$reflection = new ReflectionClass(objectOrClass: $class);
			if ($reflection->isAbstract() === true) {
				continue;
			}

			$seen++;
			foreach ($reflection->getInterfaceNames() as $contract) {
				if (str_starts_with($contract, 'OCA\\Keepiq\\Kernel\\Contracts\\') === true && isset($aliased[$contract]) === false) {
					$missing[$contract] = $class;
				}
			}
		}

		$this->assertGreaterThan(expected: 80, actual: $seen);
		$this->assertSame(expected: [], actual: $missing);
	}//end testEveryImplementedContractIsAliased()

	/**
	 * Whether a class loads here; a class built on an optional library that is absent does not.
	 *
	 * @param string $class The class name.
	 *
	 * @return bool
	 */
	private function loads(string $class): bool {
		try {
			return class_exists($class);
		} catch (\Error) {
			return false;
		}
	}//end loads()
}//end class
