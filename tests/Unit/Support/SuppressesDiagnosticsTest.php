<?php

/**
 * Unit tests for SuppressesDiagnostics.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Support
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

namespace OCA\Keepiq\Tests\Unit\Support;

use OCA\Keepiq\Support\SuppressesDiagnostics;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SuppressesDiagnostics: warnings inside the call are swallowed, the
 * result comes back, and the previous error handler is always restored.
 *
 * @covers \OCA\Keepiq\Support\SuppressesDiagnostics
 */
class SuppressesDiagnosticsTest extends TestCase {

	/**
	 * A user of the trait that exposes it.
	 *
	 * @return object
	 */
	private function subject(): object {
		return new class {
			use SuppressesDiagnostics;

			/**
			 * Run a call without diagnostics.
			 *
			 * @param callable $call The call.
			 *
			 * @return mixed
			 */
			public function quietly(callable $call): mixed {
				return $this->withoutDiagnostics($call);
			}//end quietly()
		};
	}//end subject()

	/**
	 * A warning inside the call never reaches the outer handler, and the call's result is returned.
	 *
	 * @return void
	 */
	public function testAWarningIsSwallowedAndTheResultReturned(): void {
		$outer = [];
		set_error_handler(static function (int $no, string $message) use (&$outer): bool {
			$outer[] = $message;
			return true;
		});

		try {
			$result = $this->subject()->quietly(static function (): string {
				trigger_error('noisy', E_USER_WARNING);
				return 'value';
			});
			trigger_error('after', E_USER_WARNING);
		} finally {
			restore_error_handler();
		}

		$this->assertSame('value', $result);
		$this->assertSame(['after'], $outer);
	}//end testAWarningIsSwallowedAndTheResultReturned()

	/**
	 * An exception passes through and the outer handler is still restored.
	 *
	 * @return void
	 */
	public function testAnExceptionPassesThroughAndRestoresTheHandler(): void {
		$outer = [];
		set_error_handler(static function (int $no, string $message) use (&$outer): bool {
			$outer[] = $message;
			return true;
		});

		try {
			try {
				$this->subject()->quietly(static function (): void {
					throw new RuntimeException('boom');
				});
				$this->fail('The exception was swallowed.');
			} catch (RuntimeException $e) {
				$this->assertSame('boom', $e->getMessage());
			}

			trigger_error('after', E_USER_WARNING);
		} finally {
			restore_error_handler();
		}

		$this->assertSame(['after'], $outer);
	}//end testAnExceptionPassesThroughAndRestoresTheHandler()
}//end class
