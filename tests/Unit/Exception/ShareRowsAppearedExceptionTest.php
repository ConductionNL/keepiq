<?php

/**
 * Unit tests for ShareRowsAppearedException.
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

use OCA\Keepiq\Exception\ShareRowsAppearedException;
use OCA\Keepiq\Service\JudgedShareRows;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ShareRowsAppearedException.
 *
 * @covers \OCA\Keepiq\Exception\ShareRowsAppearedException
 * @uses \OCA\Keepiq\Service\JudgedShareRows
 */
class ShareRowsAppearedExceptionTest extends TestCase {
	/**
	 * The judged rows are exposed as the same instance with a fixed message.
	 *
	 * @return void
	 */
	public function testCarriesTheJudgedRowsAndFixedMessage(): void {
		$rows = new JudgedShareRows([], static fn (): bool => true);

		$exception = new ShareRowsAppearedException($rows);

		$this->assertSame($rows, $exception->rows);
		$this->assertSame('Share rows appeared under the source lock', $exception->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testCarriesTheJudgedRowsAndFixedMessage()
}//end class
