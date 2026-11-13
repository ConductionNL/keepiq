<?php

/**
 * Unit tests for CryptoPrimitives.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\CryptoPrimitives;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the MGF1 and PBKDF2 primitives.
 *
 * @covers \OCA\Keepiq\Service\CryptoPrimitives
 */
class CryptoPrimitivesTest extends TestCase {
	public function testMgf1ProducesTheRequestedLength(): void {
		$primitives = new CryptoPrimitives();

		$this->assertSame(0, strlen($primitives->mgf1Sha256('seed', 0)));
		$this->assertSame(10, strlen($primitives->mgf1Sha256('seed', 10)));
		$this->assertSame(70, strlen($primitives->mgf1Sha256('seed', 70)));
	}

	public function testMgf1ConcatenatesCounterBlocks(): void {
		$expected = hash('sha256', 'seed' . "\x00\x00\x00\x00", true)
			. hash('sha256', 'seed' . "\x00\x00\x00\x01", true);

		$this->assertSame(substr($expected, 0, 40), (new CryptoPrimitives())->mgf1Sha256('seed', 40));
	}

	public function testMgf1PrefixIsStableAcrossLengths(): void {
		$primitives = new CryptoPrimitives();

		$this->assertSame(
			$primitives->mgf1Sha256('abc', 16),
			substr($primitives->mgf1Sha256('abc', 64), 0, 16)
		);
	}

	public function testPbkdf2UsesSixHundredThousandIterationsAndThirtyTwoBytes(): void {
		$derived = (new CryptoPrimitives())->pbkdf2Sha256('password', 'salt');

		$this->assertSame(32, strlen($derived));
		$this->assertSame(hash_pbkdf2('sha256', 'password', 'salt', 600000, 32, true), $derived);
	}
}
