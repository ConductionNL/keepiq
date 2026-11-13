<?php

/**
 * Unit tests for StaleWriteException.
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

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\StaleWriteException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for StaleWriteException.
 *
 * @covers \OCA\Keepiq\Exception\StaleWriteException
 * @uses \OCA\Keepiq\Db\Secret
 */
class StaleWriteExceptionTest extends TestCase {
	/**
	 * The exception carries the current secret and a fixed message.
	 *
	 * @return void
	 */
	public function testCarriesTheCurrentSecretAndFixedMessage(): void {
		$current = new Secret();

		$exception = new StaleWriteException($current);

		$this->assertSame($current, $exception->getCurrent());
		$this->assertNull($exception->getSourceUpdatedAt());
		$this->assertSame('This secret changed since your copy was made', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testCarriesTheCurrentSecretAndFixedMessage()

	/**
	 * The source's update time is passed through as the same instance.
	 *
	 * @return void
	 */
	public function testCarriesTheSourceUpdateTime(): void {
		$current   = new Secret();
		$updatedAt = new DateTime('2026-01-02 03:04:05');

		$exception = new StaleWriteException($current, $updatedAt);

		$this->assertSame($updatedAt, $exception->getSourceUpdatedAt());
		$this->assertSame($current, $exception->getCurrent());
	}//end testCarriesTheSourceUpdateTime()
}//end class
