<?php

/**
 * Keepiq Pattern Atoms
 *
 * The escapes, classes, group openings and quantifiers SafePattern reads alike in PCRE and JavaScript.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

/**
 * Each method answers the length of the construct at a position, or 0 (null for a group) when the two dialects
 * would read it differently.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
final class PatternAtoms {
	/**
	 * Escaped characters that read alike in both dialects, outside a class.
	 *
	 * @var string
	 */
	private const ESCAPES = 'dDwWsSbBtnrf^$\\.*+?()[]{}|/';

	/**
	 * The length of an escape, 0 when it is not one both dialects read alike. Back-references are refused: they read
	 * differently and backtrack.
	 *
	 * @param string $pattern The pattern
	 * @param int    $index   Where the `\` is
	 *
	 * @return int
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function escapeLength(string $pattern, int $index): int {
		$next = $pattern[$index + 1] ?? '';
		if ($next === 'x') {
			return $this->hexEscapeLength(pattern: $pattern, index: $index);
		}

		if ($next !== '' && str_contains(self::ESCAPES, $next) === true) {
			return 2;
		}

		return 0;
	}//end escapeLength()

	/**
	 * The length of a `\xHH` escape, 0 without two hex digits.
	 *
	 * @param string $pattern The pattern
	 * @param int    $index   Where the `\` is
	 *
	 * @return int
	 */
	private function hexEscapeLength(string $pattern, int $index): int {
		if (preg_match('/^[0-9a-fA-F]{2}$/', substr($pattern, $index + 2, 2)) === 1) {
			return 4;
		}

		return 0;
	}//end hexEscapeLength()

	/**
	 * The length of a character class, 0 when it is not one both dialects read alike: `[]` and `[^]`, or a `[`
	 * inside, such as a POSIX class.
	 *
	 * @param string $pattern The pattern
	 * @param int    $index   Where the `[` is
	 *
	 * @return int
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function classLength(string $pattern, int $index): int {
		$pos = $index + 1;
		if (($pattern[$pos] ?? '') === '^') {
			$pos++;
		}

		if (($pattern[$pos] ?? '') === ']') {
			return 0;
		}

		while ($pos < strlen($pattern)) {
			$char = $pattern[$pos];
			if ($char === ']') {
				return $pos - $index + 1;
			}

			$step = $this->classStep(pattern: $pattern, pos: $pos);
			if ($step === 0) {
				return 0;
			}

			$pos += $step;
		}

		return 0;
	}//end classLength()

	/**
	 * The opening of a group: its length and whether it is a lookahead, or null for a construct only PCRE reads,
	 * such as inline flags, an atomic group or a comment, and for a lookbehind, which PCRE refuses when its length
	 * varies but JavaScript runs.
	 *
	 * @param string $pattern The pattern
	 * @param int    $index   Where the `(` is
	 *
	 * @return array{length:int,look:bool}|null
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function groupOpening(string $pattern, int $index): ?array {
		if (($pattern[$index + 1] ?? '') !== '?') {
			return ['length' => 1, 'look' => false];
		}

		$rest = substr($pattern, $index + 2);
		if (str_starts_with($rest, ':') === true) {
			return ['length' => 3, 'look' => false];
		}

		if (preg_match('/^[=!]/', $rest) === 1) {
			return ['length' => 3, 'look' => true];
		}

		if (preg_match('/^<[A-Za-z_][A-Za-z0-9_]*>/', $rest, $match) === 1) {
			return ['length' => 2 + strlen($match[0]), 'look' => false];
		}

		return null;
	}//end groupOpening()

	/**
	 * Whether a quantifier is a choice point: `?`, `*`, `+`, `{m,}`, or `{m,n}` with m < n. A fixed `{n}` is not.
	 *
	 * @param string $quantifier The quantifier
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function isVariable(string $quantifier): bool {
		if (preg_match('/^\{(\d+)(,(\d*))?\}$/', $quantifier, $match) !== 1) {
			return true;
		}

		if (isset($match[2]) === false) {
			return false;
		}

		return $match[3] === '' || (int)$match[1] < (int)$match[3];
	}//end isVariable()

	/**
	 * The length of a quantifier, 0 when there is none.
	 *
	 * @param string $pattern The pattern
	 * @param int    $index   The position
	 *
	 * @return int
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function quantifierLength(string $pattern, int $index): int {
		if (str_contains('*+?', $pattern[$index]) === true) {
			return 1;
		}

		if (preg_match('/^\{\d+(?:,\d*)?\}/', substr($pattern, $index), $match) === 1) {
			return strlen($match[0]);
		}

		return 0;
	}//end quantifierLength()

	/**
	 * The length of one step inside a class: an escape, or one character; 0 for a `[`, which a POSIX class or a
	 * nested set would start.
	 *
	 * @param string $pattern The pattern
	 * @param int    $pos     The position
	 *
	 * @return int
	 */
	private function classStep(string $pattern, int $pos): int {
		if ($pattern[$pos] === '[') {
			return 0;
		}

		if ($pattern[$pos] !== '\\') {
			return 1;
		}

		// `\-` is a literal dash in a class; `\b` there is a backspace in one dialect only.
		return match ($pattern[$pos + 1] ?? '') {
			'-' => 2,
			'b', 'B' => 0,
			default => $this->escapeLength(pattern: $pattern, index: $pos),
		};
	}//end classStep()
}//end class
