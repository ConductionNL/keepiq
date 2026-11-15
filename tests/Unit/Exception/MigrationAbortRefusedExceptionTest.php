<?php

/**
 * Unit tests for MigrationAbortRefusedException.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Exception
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

namespace OCA\Keepiq\Tests\Unit\Exception;

use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for MigrationAbortRefusedException.
 *
 * @covers \OCA\Keepiq\Exception\MigrationAbortRefusedException
 */
class MigrationAbortRefusedExceptionTest extends TestCase {
	/**
	 * Without a committed count the exception reports zero.
	 *
	 * @return void
	 */
	public function testCommittedDefaultsToZero(): void {
		$exception = new MigrationAbortRefusedException('Abort refused');

		$this->assertSame(0, $exception->getCommitted());
		$this->assertSame('Abort refused', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testCommittedDefaultsToZero()

	/**
	 * The fluent setter returns the same instance and stores the count.
	 *
	 * @return void
	 */
	public function testWithCommittedIsFluentAndStoresTheCount(): void {
		$exception = new MigrationAbortRefusedException();

		$returned = $exception->withCommitted(42);

		$this->assertSame($exception, $returned);
		$this->assertSame(42, $exception->getCommitted());
	}//end testWithCommittedIsFluentAndStoresTheCount()
}//end class
