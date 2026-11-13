<?php

/**
 * Unit tests for ReinstateRefusedException.
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

use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ReinstateRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReinstateRefusedException.
 *
 * @covers \OCA\Keepiq\Exception\ReinstateRefusedException
 */
class ReinstateRefusedExceptionTest extends TestCase {
	/**
	 * The machine-readable error and the human message are kept apart.
	 *
	 * @return void
	 */
	public function testKeepsErrorAndMessageApart(): void {
		$exception = new ReinstateRefusedException('name_taken', 'A secret with this name already exists');

		$this->assertSame('name_taken', $exception->getError());
		$this->assertSame('A secret with this name already exists', $exception->getMessage());
	}//end testKeepsErrorAndMessageApart()

	/**
	 * A refused reinstate is a conflict, so conflict handlers catch it.
	 *
	 * @return void
	 */
	public function testIsCaughtAsAConflict(): void {
		try {
			throw new ReinstateRefusedException('folder_gone', 'The folder no longer exists');
		} catch (ConflictException $caught) {
			$this->assertInstanceOf(ReinstateRefusedException::class, $caught);
			$this->assertSame('folder_gone', $caught->getError());
			return;
		}

		$this->fail('ReinstateRefusedException was not caught as a ConflictException');
	}//end testIsCaughtAsAConflict()
}//end class
