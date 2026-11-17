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
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
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
			'a repeated optional'         => ['(a?){5}'],
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
