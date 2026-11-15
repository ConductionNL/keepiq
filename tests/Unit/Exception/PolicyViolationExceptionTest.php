<?php

/**
 * Unit tests for PolicyViolationException.
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
use OCA\Keepiq\Exception\PolicyViolationException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PolicyViolationException.
 *
 * @covers \OCA\Keepiq\Exception\PolicyViolationException
 */
class PolicyViolationExceptionTest extends TestCase {
	/**
	 * The policy code is exposed both as property and through policyCode().
	 *
	 * @return void
	 */
	public function testExposesThePolicyCode(): void {
		$exception = new PolicyViolationException('min_length', 'Password too short');

		$this->assertSame('min_length', $exception->policyCode);
		$this->assertSame('min_length', $exception->policyCode());
		$this->assertSame('Password too short', $exception->getMessage());
	}//end testExposesThePolicyCode()

	/**
	 * Caught as a generic ForbiddenException it still reports its own code.
	 *
	 * @return void
	 */
	public function testPolicyCodeSurvivesCatchingAsForbidden(): void {
		try {
			throw new PolicyViolationException('no_export', 'Export is disabled');
		} catch (ForbiddenException $caught) {
			$this->assertSame('no_export', $caught->policyCode());
			return;
		}

		$this->fail('PolicyViolationException was not caught as a ForbiddenException');
	}//end testPolicyCodeSurvivesCatchingAsForbidden()
}//end class
