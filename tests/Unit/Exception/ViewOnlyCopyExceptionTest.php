<?php

/**
 * Unit tests for ViewOnlyCopyException.
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ViewOnlyCopyException.
 *
 * @covers \OCA\Keepiq\Exception\ViewOnlyCopyException
 */
class ViewOnlyCopyExceptionTest extends TestCase {
	/**
	 * The refusal uses the shared view-only wording and the view_only code.
	 *
	 * @return void
	 */
	public function testUsesTheViewOnlyWordingAndCode(): void {
		$exception = new ViewOnlyCopyException();

		$this->assertSame(Secret::VIEW_ONLY_REFUSAL, $exception->getMessage());
		$this->assertSame('view_only', ViewOnlyCopyException::CODE);
		$this->assertSame('view_only', $exception->policyCode());
	}//end testUsesTheViewOnlyWordingAndCode()

	/**
	 * Caught as a generic ForbiddenException it still reports view_only.
	 *
	 * @return void
	 */
	public function testPolicyCodeSurvivesCatchingAsForbidden(): void {
		try {
			throw new ViewOnlyCopyException();
		} catch (ForbiddenException $caught) {
			$this->assertSame('view_only', $caught->policyCode());
			return;
		}

		$this->fail('ViewOnlyCopyException was not caught as a ForbiddenException');
	}//end testPolicyCodeSurvivesCatchingAsForbidden()
}//end class
