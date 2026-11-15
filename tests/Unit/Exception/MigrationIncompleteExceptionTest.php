<?php

/**
 * Unit tests for MigrationIncompleteException.
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

use OCA\Keepiq\Exception\MigrationIncompleteException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for MigrationIncompleteException.
 *
 * @covers \OCA\Keepiq\Exception\MigrationIncompleteException
 */
class MigrationIncompleteExceptionTest extends TestCase {
	/**
	 * Without an acknowledgement requirement the getter reports null.
	 *
	 * @return void
	 */
	public function testRequiredAcknowledgementDefaultsToNull(): void {
		$exception = new MigrationIncompleteException('Migration incomplete');

		$this->assertNull($exception->getRequiredAcknowledgement());
		$this->assertSame('Migration incomplete', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testRequiredAcknowledgementDefaultsToNull()

	/**
	 * The fluent setter returns the same instance and stores the count.
	 *
	 * @return void
	 */
	public function testWithRequiredAcknowledgementIsFluentAndStoresTheCount(): void {
		$exception = new MigrationIncompleteException('Migration incomplete');

		$returned = $exception->withRequiredAcknowledgement(3);

		$this->assertSame($exception, $returned);
		$this->assertSame(3, $exception->getRequiredAcknowledgement());
	}//end testWithRequiredAcknowledgementIsFluentAndStoresTheCount()

	/**
	 * Zero is kept as zero, not collapsed to null.
	 *
	 * @return void
	 */
	public function testZeroAcknowledgementIsKeptDistinctFromNull(): void {
		$exception = (new MigrationIncompleteException())->withRequiredAcknowledgement(0);

		$this->assertSame(0, $exception->getRequiredAcknowledgement());
	}//end testZeroAcknowledgementIsKeptDistinctFromNull()

	/**
	 * A later call overrides the earlier requirement.
	 *
	 * @return void
	 */
	public function testLaterAcknowledgementOverridesEarlierOne(): void {
		$exception = (new MigrationIncompleteException())
			->withRequiredAcknowledgement(5)
			->withRequiredAcknowledgement(2);

		$this->assertSame(2, $exception->getRequiredAcknowledgement());
	}//end testLaterAcknowledgementOverridesEarlierOne()
}//end class
