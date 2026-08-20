<?php

/**
 * Doriath Key Generator Regex Parser
 *
 * Parses simple generation regexes into a character set and a length range.
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use InvalidArgumentException;

/**
 * Parses a generation regex into its resolvable character set and length range.
 *
 * Supports a single character class (optionally negated) with a length
 * quantifier. Negated classes are resolved as the complement against printable
 * ASCII (0x21-0x7E). Arbitrary regex features (lookarounds, alternation,
 * backreferences) are intentionally unsupported.
 */
class KeyGeneratorRegexParser {
	/**
	 * Uppercase letters used when expanding \w.
	 *
	 * @var string
	 */
	private const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * Lowercase letters used when expanding \w.
	 *
	 * @var string
	 */
	private const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Digits used when expanding \d and \w.
	 *
	 * @var string
	 */
	private const DIGITS = '0123456789';

	/**
	 * Wrap a raw regex pattern in delimiters for use with preg_* functions.
	 *
	 * If the pattern already begins and ends with a common delimiter (optionally
	 * followed by flag letters) it is returned unchanged.
	 *
	 * @param string $pattern The raw regex pattern
	 *
	 * @return string The delimited pattern
	 */
	public function delimit(string $pattern): string {
		if ($pattern === '') {
			return '//';
		}

		$first = $pattern[0];
		if (in_array($first, ['/', '#', '~'], true) === true
			&& strlen($pattern) >= 2
			&& preg_match('/' . preg_quote($first, '/') . '[a-zA-Z]*$/', $pattern) === 1
		) {
			return $pattern;
		}

		return '#' . str_replace('#', '\#', $pattern) . '#';
	}//end delimit()

	/**
	 * Assert that a delimited pattern is syntactically valid.
	 *
	 * @param string $delimited The delimited regex pattern
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the pattern is invalid
	 */
	public function assertValid(string $delimited): void {
		set_error_handler(
			static function (): bool {
				return true;
			}
		);
		$result = preg_match($delimited, '');
		restore_error_handler();

		if ($result === false) {
			throw new InvalidArgumentException(message: 'The regex pattern is syntactically invalid');
		}
	}//end assertValid()

	/**
	 * Extract the length quantifier ({n} or {n,m}) from a regex.
	 *
	 * @param string $regex The regex pattern
	 *
	 * @return array{0:int,1:int} The [minLength, maxLength] pair
	 *
	 * @throws InvalidArgumentException When no quantifier is present or the range is invalid
	 *
	 * @spec openspec/specs/key-generator/spec.md#requirement-regex-override
	 */
	public function extractLength(string $regex): array {
		if (preg_match('/\{(\d+)(?:,(\d+))?\}/', $regex, $matches) !== 1) {
			throw new InvalidArgumentException(
				message: 'The regex must contain a length quantifier (e.g. {16} or {8,16})'
			);
		}

		$min = (int)$matches[1];
		$max = $min;
		// Group 2 is the LAST group in the pattern, and PHP omits a trailing
		// unmatched group from $matches rather than setting it to '' — so
		// isset() already covers the `{16}` case and the `!== ''` this replaces
		// was unreachable. (The '' form only happens for MIDDLE groups.)
		if (isset($matches[2]) === true) {
			$max = (int)$matches[2];
		}

		if ($max < $min) {
			throw new InvalidArgumentException(message: 'The regex length range is invalid (max < min)');
		}

		return [$min, $max];
	}//end extractLength()

	/**
	 * Extract the resolvable character set from a regex character class.
	 *
	 * @param string $regex The regex pattern
	 *
	 * @return string The resolved character set (each char unique)
	 *
	 * @throws InvalidArgumentException When no character class can be determined
	 */
	public function extractCharset(string $regex): string {
		if (preg_match('/\[(\^?)((?:\\\\.|[^\]\\\\])*)\]/', $regex, $matches) !== 1) {
			throw new InvalidArgumentException(
				message: 'The regex must contain a character class (e.g. [a-zA-Z0-9])'
			);
		}

		$allowed = $this->expandCharacterClass(classBody: $matches[2]);
		if ($matches[1] === '^') {
			$allowed = $this->complementAscii(disallowed: $allowed);
		}

		return $this->dedupe(chars: $allowed);
	}//end extractCharset()

	/**
	 * De-duplicate a character list into a string, preserving order.
	 *
	 * @param array<int,string> $chars The character list
	 *
	 * @return string The de-duplicated string
	 */
	private function dedupe(array $chars): string {
		$resolved = '';
		$seen = [];
		foreach ($chars as $char) {
			if (isset($seen[$char]) === true) {
				continue;
			}

			$seen[$char] = true;
			$resolved .= $char;
		}

		return $resolved;
	}//end dedupe()

	/**
	 * Expand a character-class body into a flat list of characters.
	 *
	 * @param string $classBody The inside of the character class
	 *
	 * @return array<int,string> The expanded characters
	 */
	private function expandCharacterClass(string $classBody): array {
		$chars = [];
		$length = strlen($classBody);
		$position = 0;

		while ($position < $length) {
			$char = $classBody[$position];

			if ($char === '\\' && ($position + 1) < $length) {
				$chars = array_merge($chars, $this->expandEscape(escape: $classBody[($position + 1)]));
				$position = ($position + 2);
				continue;
			}

			$range = $this->expandRange(classBody: $classBody, position: $position, length: $length);
			if ($range !== null) {
				$chars = array_merge($chars, $range);
				$position = ($position + 3);
				continue;
			}

			$chars[] = $char;
			$position = ($position + 1);
		}//end while

		return $chars;
	}//end expandCharacterClass()

	/**
	 * Expand an a-z style range starting at the given position, if present.
	 *
	 * @param string $classBody The character-class body
	 * @param int $position The current scan position
	 * @param int $length The body length
	 *
	 * @return array<int,string>|null The range characters, or null if not a range
	 */
	private function expandRange(string $classBody, int $position, int $length): ?array {
		if (($position + 2) >= $length
			|| $classBody[($position + 1)] !== '-'
			|| $classBody[($position + 2)] === ']'
		) {
			return null;
		}

		$start = ord($classBody[$position]);
		$end = ord($classBody[($position + 2)]);
		if ($end < $start) {
			return null;
		}

		$chars = [];
		for ($code = $start; $code <= $end; $code++) {
			$chars[] = chr($code);
		}

		return $chars;
	}//end expandRange()

	/**
	 * Expand a single-character escape inside a character class.
	 *
	 * @param string $escape The escaped character (the part after the backslash)
	 *
	 * @return array<int,string> The characters the escape represents
	 */
	private function expandEscape(string $escape): array {
		switch ($escape) {
			case 'd':
				return str_split(self::DIGITS);
			case 'w':
				return str_split(self::UPPERCASE . self::LOWERCASE . self::DIGITS . '_');
			case 's':
				// Whitespace is not a useful generation set; represent as a space.
				return [' '];
			default:
				// Literal escaped character (e.g. \. \\ \] ).
				return [$escape];
		}
	}//end expandEscape()

	/**
	 * Compute the complement of a disallowed set against printable ASCII (0x21-0x7E).
	 *
	 * @param array<int,string> $disallowed The disallowed characters
	 *
	 * @return array<int,string> The allowed characters
	 */
	private function complementAscii(array $disallowed): array {
		$blocked = [];
		foreach ($disallowed as $char) {
			$blocked[$char] = true;
		}

		// \s in a negated class blocks all whitespace, not just the space character.
		if (isset($blocked[' ']) === true) {
			$blocked["\t"] = true;
			$blocked["\n"] = true;
			$blocked["\r"] = true;
		}

		$allowed = [];
		for ($code = 0x21; $code <= 0x7E; $code++) {
			$char = chr($code);
			if (isset($blocked[$char]) === true) {
				continue;
			}

			$allowed[] = $char;
		}

		return $allowed;
	}//end complementAscii()
}//end class
