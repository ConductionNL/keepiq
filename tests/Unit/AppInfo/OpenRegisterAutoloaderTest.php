<?php

/**
 * Tests for the OpenRegister autoload prelude.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use OCA\Keepiq\AppInfo\OpenRegisterAutoloader;
use PHPUnit\Framework\TestCase;

/**
 * The prelude's whole purpose is that it CANNOT take down the caller.
 *
 * The defect it exists to prevent is an exception escaping the composition
 * root: an `\Error` thrown while resolving `OCA\OpenRegister\AppHost\Bootstrap`
 * aborted Keepiq's entire `Application::register()`, so every listener
 * registered below it silently never ran. A prelude that can itself throw would
 * reintroduce exactly that failure, so "never throws" is the contract under
 * test — on ANY instance, with OpenRegister present or absent.
 */
class OpenRegisterAutoloaderTest extends TestCase {

	/**
	 * The prelude must never throw, whatever the instance looks like.
	 *
	 * This runs in both environments the suite is executed in: with Nextcloud
	 * booted (where OpenRegister may or may not be installed) and with only the
	 * OCP stubs registered (where `\OCP\Server::get()` cannot resolve anything).
	 * Both must be swallowed.
	 *
	 * @return void
	 */
	public function testRegisterNeverThrows(): void {
		$result = OpenRegisterAutoloader::register();

		$this->assertIsBool(
			$result,
			'The prelude must report success or failure as a bool, never throw.'
		);

	}//end testRegisterNeverThrows()

	/**
	 * Calling the prelude twice must be free and must agree with itself.
	 *
	 * `OC_App::registerAutoloading()` early-returns on an `$alreadyRegistered`
	 * key, so a second call is a no-op. Application::register() may run more
	 * than once in a single process (web + occ share no state, but tests and
	 * repair steps do), and a prelude that failed or threw on the second call
	 * would be a latent bootstrap defect.
	 *
	 * @return void
	 */
	public function testRegisterIsIdempotent(): void {
		$first = OpenRegisterAutoloader::register();
		$second = OpenRegisterAutoloader::register();

		$this->assertSame(
			$first,
			$second,
			'The prelude is idempotent, so repeated calls must agree.'
		);

	}//end testRegisterIsIdempotent()

	/**
	 * Map a class name through the prelude's PSR-4 rule.
	 *
	 * @param string $class The class name.
	 *
	 * @return string|null The mapped file.
	 */
	private function mapped(string $class): ?string {
		$method = new \ReflectionMethod(OpenRegisterAutoloader::class, 'classFile');
		$method->setAccessible(true);

		return $method->invoke(null, '/var/www/apps/openregister', $class);

	}//end mapped()

	/**
	 * An OpenRegister class maps to its file under lib/.
	 *
	 * This is the rule Nextcloud used to own via `addPsr4()`. Since the prelude
	 * stopped calling private API it owns the rule itself, so the rule is pinned
	 * here: namespace separators become directory separators, the prefix is
	 * stripped, and `lib/` is the root.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAnOpenRegisterClassMapsUnderLib(): void {
		$this->assertSame(
			'/var/www/apps/openregister/lib/AppHost/Bootstrap.php',
			$this->mapped('OCA\\OpenRegister\\AppHost\\Bootstrap')
		);

	}//end testAnOpenRegisterClassMapsUnderLib()

	/**
	 * A class this prelude does not own is refused, not guessed at.
	 *
	 * An autoloader that answers for names outside its prefix can shadow the
	 * loader that would have resolved them — including this app's own. The
	 * near-miss `OCA\OpenRegisterExtra\…` is included on purpose: a prefix test
	 * written without the trailing separator matches it.
	 *
	 * @param string $class A class the prelude must not claim.
	 *
	 * @return void
	 *
	 * @dataProvider foreignClassProvider
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testAForeignClassIsNotClaimed(string $class): void {
		$this->assertNull($this->mapped($class));

	}//end testAForeignClassIsNotClaimed()

	/**
	 * Class names the prelude must refuse.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function foreignClassProvider(): array {
		return [
			'this app' => ['OCA\\Keepiq\\AppInfo\\Application'],
			'another vendor' => ['OCP\\IRequest'],
			'prefix near-miss' => ['OCA\\OpenRegisterExtra\\Thing'],
			'the bare prefix' => ['OCA\\OpenRegister\\'],
		];

	}//end foreignClassProvider()

}//end class
