<?php

/**
 * Unit tests for KeyGeneratorRegexParser.
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
use OCA\Keepiq\Service\KeyGeneratorRegexParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for delimiting, validating and decomposing generator regexes.
 *
 * @covers \OCA\Keepiq\Service\KeyGeneratorRegexParser
 */
class KeyGeneratorRegexParserTest extends TestCase {
	private KeyGeneratorRegexParser $parser;

	protected function setUp(): void {
		$this->parser = new KeyGeneratorRegexParser();
	}

	public function testDelimitWrapsAnEmptyPattern(): void {
		$this->assertSame('//', $this->parser->delimit(''));
	}

	public function testDelimitKeepsAnAlreadyDelimitedPattern(): void {
		$this->assertSame('/[a-z]{8}/', $this->parser->delimit('/[a-z]{8}/'));
		$this->assertSame('#[0-9]{4}#i', $this->parser->delimit('#[0-9]{4}#i'));
		$this->assertSame('~x~', $this->parser->delimit('~x~'));
	}

	public function testDelimitWrapsABarePatternAndEscapesHashes(): void {
		$this->assertSame('#[a-z]{8}#', $this->parser->delimit('[a-z]{8}'));
		$this->assertSame('#[\#a]{2}#', $this->parser->delimit('[#a]{2}'));
	}

	public function testDelimitWrapsAPatternWhoseLeadingDelimiterIsNotClosed(): void {
		$this->assertSame('#/ab1#', $this->parser->delimit('/ab1'));
		$this->assertSame('#/#', $this->parser->delimit('/'));
	}

	public function testAssertValidAcceptsAValidRegex(): void {
		$this->parser->assertValid('#[a-z]{8}#');
		$this->addToAssertionCount(1);
	}

	public function testAssertValidRejectsABrokenRegex(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The regex pattern is syntactically invalid');
		$this->parser->assertValid('#[a-z{8#');
	}

	public function testExtractLengthReadsAFixedLength(): void {
		$this->assertSame([16, 16], $this->parser->extractLength('[a-z]{16}'));
	}

	public function testExtractLengthReadsARange(): void {
		$this->assertSame([8, 12], $this->parser->extractLength('[a-z]{8,12}'));
	}

	public function testExtractLengthRequiresAQuantifier(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('length quantifier');
		$this->parser->extractLength('[a-z]+');
	}

	public function testExtractLengthRejectsAnInvertedRange(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('max < min');
		$this->parser->extractLength('[a-z]{12,8}');
	}

	public function testExtractCharsetExpandsRangesAndLiterals(): void {
		$this->assertSame('abcXYZ_', $this->parser->extractCharset('[a-cX-Z_]{4}'));
	}

	public function testExtractCharsetDeduplicatesKeepingFirstOccurrence(): void {
		$this->assertSame('abc', $this->parser->extractCharset('[a-cba]{4}'));
	}

	public function testExtractCharsetExpandsEscapes(): void {
		$this->assertSame('0123456789', $this->parser->extractCharset('[\d]{4}'));
		$this->assertSame(
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_',
			$this->parser->extractCharset('[\w]{4}')
		);
		$this->assertSame(' ', $this->parser->extractCharset('[\s]{4}'));
		$this->assertSame('.]', $this->parser->extractCharset('[\.\]]{4}'));
	}

	public function testExtractCharsetKeepsAReversedRangeAsLiterals(): void {
		$this->assertSame('z-a', $this->parser->extractCharset('[z-a]{3}'));
	}

	public function testExtractCharsetKeepsATrailingDashLiteral(): void {
		$this->assertSame('a-', $this->parser->extractCharset('[a-]{3}'));
	}

	public function testExtractCharsetComplementsANegatedClassOverPrintableAscii(): void {
		$charset = $this->parser->extractCharset('[^a-z]{4}');

		$this->assertStringNotContainsString('a', $charset);
		$this->assertStringNotContainsString('z', $charset);
		$this->assertStringContainsString('A', $charset);
		$this->assertStringContainsString('~', $charset);
		$this->assertSame(94 - 26, strlen($charset));
		$this->assertSame('!', $charset[0]);
	}

	public function testExtractCharsetNegatedWhitespaceStillYieldsPrintables(): void {
		$charset = $this->parser->extractCharset('[^\s]{4}');

		$this->assertSame(94, strlen($charset));
		$this->assertStringNotContainsString("\t", $charset);
	}

	public function testExtractCharsetRequiresACharacterClass(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('character class');
		$this->parser->extractCharset('abc{4}');
	}
}
