<?php

/**
 * Unit tests for FieldsNotReencryptedException.
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

use OCA\Keepiq\Exception\FieldsNotReencryptedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for FieldsNotReencryptedException.
 *
 * @covers \OCA\Keepiq\Exception\FieldsNotReencryptedException
 */
class FieldsNotReencryptedExceptionTest extends TestCase {
	/**
	 * A fresh exception names no fields.
	 *
	 * @return void
	 */
	public function testFieldsDefaultToEmptyList(): void {
		$exception = new FieldsNotReencryptedException('Not re-encrypted');

		$this->assertSame([], $exception->getFields());
		$this->assertSame('Not re-encrypted', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testFieldsDefaultToEmptyList()

	/**
	 * The fluent setter returns the same instance and keeps the fields in order.
	 *
	 * @return void
	 */
	public function testWithFieldsIsFluentAndKeepsOrder(): void {
		$exception = new FieldsNotReencryptedException();

		$returned = $exception->withFields(['totp', 'key', 'passkeys']);

		$this->assertSame($exception, $returned);
		$this->assertSame(['totp', 'key', 'passkeys'], $exception->getFields());
	}//end testWithFieldsIsFluentAndKeepsOrder()

	/**
	 * A second call replaces the list rather than appending to it.
	 *
	 * @return void
	 */
	public function testWithFieldsReplacesThePreviousList(): void {
		$exception = (new FieldsNotReencryptedException())
			->withFields(['key'])
			->withFields(['login']);

		$this->assertSame(['login'], $exception->getFields());
	}//end testWithFieldsReplacesThePreviousList()
}//end class
