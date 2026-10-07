<?php

/**
 * Unit tests for tag normalisation (vault-favourites-tags-and-last-used).
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

use InvalidArgumentException;
use OCA\Keepiq\Service\SecretTagNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Tags are trimmed, lowercased, deduplicated, at most 32 characters and at most 20.
 */
class SecretTagNormaliserTest extends TestCase {
	/**
	 * Trim, lowercase, drop empties and duplicates, keep the first order.
	 *
	 * @return void
	 */
	public function testNormalises(): void {
		$tags = (new SecretTagNormaliser())->normalise([' On Call ', 'FINANCE', 'on call', '', '   ', 'Ünïcode']);

		$this->assertSame(['on call', 'finance', 'ünïcode'], $tags);
	}//end testNormalises()

	/**
	 * A tag of 32 characters passes; 33 is refused, counted in characters, not bytes.
	 *
	 * @return void
	 */
	public function testTagLengthIsCappedAt32Characters(): void {
		$normaliser = new SecretTagNormaliser();
		$this->assertSame([str_repeat('é', 32)], $normaliser->normalise([str_repeat('é', 32)]));

		$this->expectException(InvalidArgumentException::class);
		$normaliser->normalise([str_repeat('a', 33)]);
	}//end testTagLengthIsCappedAt32Characters()

	/**
	 * Twenty tags pass; twenty-one distinct tags are refused.
	 *
	 * @return void
	 */
	public function testAtMostTwentyTags(): void {
		$normaliser = new SecretTagNormaliser();
		$twenty     = array_map(static fn (int $i): string => 'tag'.$i, range(1, 20));
		$this->assertCount(20, $normaliser->normalise($twenty));

		$this->expectException(InvalidArgumentException::class);
		$normaliser->normalise([...$twenty, 'tag21']);
	}//end testAtMostTwentyTags()

	/**
	 * A value that is not a string is refused rather than cast.
	 *
	 * @return void
	 */
	public function testNonStringIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new SecretTagNormaliser())->normalise([['nested']]);
	}//end testNonStringIsRefused()
}//end class
