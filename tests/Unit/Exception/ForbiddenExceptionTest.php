<?php

/**
 * Unit tests for ForbiddenException.
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

use OCA\Keepiq\Exception\ForbiddenException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ForbiddenException.
 *
 * @covers \OCA\Keepiq\Exception\ForbiddenException
 */
class ForbiddenExceptionTest extends TestCase {
	/**
	 * A plain forbidden refusal carries no policy code.
	 *
	 * @return void
	 */
	public function testPlainRefusalHasNoPolicyCode(): void {
		$exception = new ForbiddenException('Not allowed');

		$this->assertNull($exception->policyCode());
		$this->assertSame('Not allowed', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testPlainRefusalHasNoPolicyCode()
}//end class
