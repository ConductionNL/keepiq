<?php

/**
 * Keepiq - SafePattern tests
 *
 * A requester's pattern runs on every keystroke in the recipient's browser, so one that can backtrack for ever, or
 * that PCRE and JavaScript read differently, is refused. tests/vitest/safe-pattern.spec.js checks the same lists
 * against the browser's copy of the rules.
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

use OCA\Keepiq\Service\SafePattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the safe-pattern check.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
class SafePatternTest extends TestCase {

	/**
	 * Patterns a field may carry.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function safe(): array {
		return [
			'digits'            => ['^[0-9]{4,6}$'],
			'an IBAN'           => ['^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$'],
			'a repeated group'  => ['^(ab)+$'],
			'a non-capturing group' => ['^(?:https?)://'],
			'an optional quantified group' => ['^(a+)?$'],
			'a named group'     => ['(?<year>\d{4})-\d{2}'],
			'a hex escape and an escaped dash' => ['^\x41[\-a-z]*$'],
			'a lookahead'       => ['(?=.*\d)\w{8,}'],
			'word boundaries'   => ['\bfoo\b'],
			'three choice points, anchored' => ['^[^@\s]+@[^@\s]+\.[^@\s]+$'],
			'two choice points, unanchored' => ['\d+-\d+'],
			'three bounded ranges' => ['^\d{1,30}\d{1,30}\d{1,30}$'],
			'two alternation groups and an optional' => ['^(a|b)(c|d)e?$'],
			'fixed counts are no choice points' => ['^\d{2}\d{2,2}[a-z]+[a-z]*\d?$'],
			'300 characters outside the BMP' => [str_repeat("\u{1F600}", 300)],
			'a counted group within the written-out cap' => ['^(?:\d\d-){400}$'],
		];
	}//end safe()

	/**
	 * Patterns that backtrack for ever, read differently, or do not compile.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unsafe(): array {
		return [
			'a doubly grouped quantifier' => ['^((a+))+$'],
			'a repeated alternation'      => ['^(a|a)+$'],
			'a repeated overlapping alternation' => ['^(a|aa)+$'],
			'a counted nested quantifier' => ['^(a+){10}$'],
			'a nested quantifier'         => ['^(a+)+$'],
			'a bound PCRE does not compile' => ['^x{0,70000}$'],
			'a counted group PCRE does not compile' => ['(a){6553}'],
			'a counted non-capturing group PCRE does not compile' => ['(?:a){8192}'],
			'a longer counted group PCRE does not compile' => ['(?:abcd){4681}'],
			'counted groups written out too long together' => ['(?:abcd){400}(?:abcd){200}'],
			'a repeated optional'         => ['(a?){5}'],
			'four open-ended quantifiers' => ['^\d*\d*\d*\d*x$'],
			'eight dot-stars'             => ['^.*.*.*.*.*.*.*.*=$'],
			'three, unanchored'           => ['\d*\d*\d*x'],
			'an anchor undone by an alternation' => ['a|^\d*\d*\d*x'],
			'large bounds'                => ['^\d{0,99}\d{0,99}\d{0,99}\d{0,99}x$'],
			'four bounded ranges'         => ['^\d{1,30}\d{1,30}\d{1,30}\d{1,30}$'],
			'28 alternation groups'       => ['^' . str_repeat('(a|a)', 28) . '$'],
			'six small bounded ranges'    => ['^' . str_repeat('a{0,64}', 6) . '$'],
			'four alternation groups'     => ['^(a|b)(c|d)(e|f)(g|h)$'],
			'a variable lookbehind'       => ['(?<=a+)b'],
			'an anchored lookbehind'      => ['^(?<=x*)y$'],
			'a bounded lookbehind'        => ['(?<=a{1,3})b'],
			'an inline flag'              => ['(?i)abc'],
			'a possessive quantifier'     => ['a++b'],
			'an atomic group'             => ['(?>a+)b'],
			'subject anchors'             => ['\Aabc\z'],
			'an end anchor'               => ['abc\Z'],
			'a comment'                   => ['(?#c)a'],
			'a POSIX class'               => ['[[:alpha:]]'],
			'a lone brace'                => ['a{'],
			'a back-reference'            => ['(a)\1'],
			'no closing bracket'          => ['^[0-9'],
			'a repeated lookahead'        => ['(?=a)+'],
			'empty'                       => [''],
			'too long'                    => [str_repeat('x', 501)],
		];
	}//end unsafe()

	/**
	 * A safe pattern is accepted.
	 *
	 * @param string $pattern The pattern.
	 *
	 * @return void
	 */
	#[DataProvider('safe')]
	public function testAcceptsASafePattern(string $pattern): void {
		$this->assertTrue((new SafePattern())->isSafe(pattern: $pattern));
	}//end testAcceptsASafePattern()

	/**
	 * An unsafe pattern is refused.
	 *
	 * @param string $pattern The pattern.
	 *
	 * @return void
	 */
	#[DataProvider('unsafe')]
	public function testRefusesAnUnsafePattern(string $pattern): void {
		$this->assertFalse((new SafePattern())->isSafe(pattern: $pattern));
	}//end testRefusesAnUnsafePattern()
}//end class
