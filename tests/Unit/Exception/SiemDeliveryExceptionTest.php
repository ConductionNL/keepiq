<?php

/**
 * Unit tests for SiemDeliveryException.
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

use OCA\Keepiq\Exception\SiemDeliveryException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SiemDeliveryException.
 *
 * @covers \OCA\Keepiq\Exception\SiemDeliveryException
 */
class SiemDeliveryExceptionTest extends TestCase {
	/**
	 * Without a status (e.g. a transport failure) the HTTP status is null.
	 *
	 * @return void
	 */
	public function testHttpStatusDefaultsToNull(): void {
		$exception = new SiemDeliveryException('Connection refused');

		$this->assertNull($exception->getHttpStatus());
		$this->assertSame('Connection refused', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testHttpStatusDefaultsToNull()

	/**
	 * The collector's status is carried as given and not used as the exception code.
	 *
	 * @return void
	 */
	public function testCarriesTheHttpStatusSeparatelyFromTheCode(): void {
		$exception = new SiemDeliveryException('Collector rejected the batch', 503);

		$this->assertSame(503, $exception->getHttpStatus());
		$this->assertSame(0, $exception->getCode());
	}//end testCarriesTheHttpStatusSeparatelyFromTheCode()
}//end class
