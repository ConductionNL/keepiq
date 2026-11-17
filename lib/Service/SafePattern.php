<?php

/**
 * Keepiq Safe Pattern
 *
 * Whether a field schema's pattern is safe to run on every keystroke and reads the same in PCRE and JavaScript.
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
 * A requester sets a field's pattern and the recipient's browser runs it with no time limit, so a pattern that can
 * backtrack for ever is refused, and so is any construct only one of PCRE and JavaScript reads. A repeated group must
 * hold no quantifier and no alternation at any depth: `(a+)+`, `(a|aa)+` and `(a+){10}` are refused; `?` repeats
 * nothing, so it may follow such a group. src/utils/safePattern.js holds the same rules for the browser.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
final class SafePattern {
	/**
	 * The longest pattern a schema may carry.
	 *
	 * @var integer
	 */
	public const MAX_LENGTH = 500;

	/**
	 * What a quantifier repeats: a plain atom.
	 *
	 * @var array{group:bool,inner:bool}
	 */
	private const ATOM = ['group' => false, 'inner' => false];

	/**
	 * The open groups: whether each holds a quantifier or alternation, and whether it is a lookaround.
	 *
	 * @var list<array{inner:bool,look:bool}>
	 */
	private array $stack = [];

	/**
	 * The pattern being scanned.
	 *
	 * @var string
	 */
	private string $pattern = '';

	/**
	 * Constructor for SafePattern.
	 *
	 * @param PatternAtoms $atoms Reads escapes, classes and quantifiers
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private PatternAtoms $atoms = new PatternAtoms(),
	) {
	}//end __construct()

	/**
	 * Whether a pattern is safe: not too long, compiles in PCRE, and passes the scan. It is only compiled, never run.
	 *
	 * @param string $pattern The pattern
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function isSafe(string $pattern): bool {
		if ($pattern === '' || mb_strlen($pattern) > self::MAX_LENGTH || str_contains($pattern, "\x01") === true) {
			return false;
		}

		// A pattern that does not compile raises a warning; it only means no.
		set_error_handler(static fn (): bool => true);
		try {
			$compiles = preg_match("\x01" . $pattern . "\x01u", '') !== false;
		} finally {
			restore_error_handler();
		}

		return $compiles === true && $this->scan(pattern: $pattern) === true;
	}//end isSafe()

	/**
	 * Walk the pattern once.
	 *
	 * @param string $pattern The pattern
	 *
	 * @return bool True when nothing unsafe was found
	 */
	private function scan(string $pattern): bool {
		$this->pattern = $pattern;
		$this->stack   = [['inner' => false, 'look' => false]];
		$last          = null;
		$index         = 0;
		while ($index < strlen($pattern)) {
			$step = $this->token(index: $index, last: $last);
			if ($step === null) {
				return false;
			}

			[$index, $last] = $step;
		}

		return count($this->stack) === 1;
	}//end scan()

	/**
	 * Read one token.
	 *
	 * @param int                               $index Where the token starts
	 * @param array{group:bool,inner:bool}|null $last  What a quantifier here would repeat
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool}|null}|null Where the next token starts and what it may repeat, or null to refuse
	 */
	private function token(int $index, ?array $last): ?array {
		return match ($this->pattern[$index]) {
			'\\' => $this->escapeToken(index: $index),
			'[' => $this->classToken(index: $index),
			'(' => $this->openGroup(index: $index),
			')' => $this->closeGroup(index: $index),
			'|' => $this->alternate(index: $index),
			default => $this->otherToken(index: $index, last: $last),
		};
	}//end token()

	/**
	 * Read an escape outside a class. A word boundary is an assertion, so there is nothing to repeat.
	 *
	 * @param int $index Where the `\` is
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool}|null}|null
	 */
	private function escapeToken(int $index): ?array {
		$next = $this->pattern[$index + 1] ?? '';
		if ($next === 'b' || $next === 'B') {
			return [$index + 2, null];
		}

		$length = $this->atoms->escapeLength(pattern: $this->pattern, index: $index);
		if ($length === 0) {
			return null;
		}

		return [$index + $length, self::ATOM];
	}//end escapeToken()

	/**
	 * Read a character class.
	 *
	 * @param int $index Where the `[` is
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool}}|null
	 */
	private function classToken(int $index): ?array {
		$length = $this->atoms->classLength(pattern: $this->pattern, index: $index);
		if ($length === 0) {
			return null;
		}

		return [$index + $length, self::ATOM];
	}//end classToken()

	/**
	 * Open a group: plain, non-capturing, named, or a lookaround. Inline flags, atomic groups, comments and the like
	 * are PCRE only.
	 *
	 * @param int $index Where the `(` is
	 *
	 * @return array{0:int,1:null}|null
	 */
	private function openGroup(int $index): ?array {
		$opening = $this->atoms->groupOpening(pattern: $this->pattern, index: $index);
		if ($opening === null) {
			return null;
		}

		$this->stack[] = ['inner' => false, 'look' => $opening['look']];
		return [$index + $opening['length'], null];
	}//end openGroup()

	/**
	 * Close a group, passing what it holds on to the group around it. A lookaround cannot be repeated.
	 *
	 * @param int $index Where the `)` is
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool}|null}|null
	 */
	private function closeGroup(int $index): ?array {
		if (count($this->stack) === 1) {
			return null;
		}

		$closed = array_pop($this->stack);
		$top    = array_key_last($this->stack);
		$this->stack[$top]['inner'] = $this->stack[$top]['inner'] || $closed['inner'];
		if ($closed['look'] === true) {
			return [$index + 1, null];
		}

		return [$index + 1, ['group' => true, 'inner' => $closed['inner']]];
	}//end closeGroup()

	/**
	 * An alternation: the group it is in now holds one.
	 *
	 * @param int $index Where the `|` is
	 *
	 * @return array{0:int,1:null}
	 */
	private function alternate(int $index): array {
		$this->stack[array_key_last($this->stack)]['inner'] = true;
		return [$index + 1, null];
	}//end alternate()

	/**
	 * A quantifier, a lone brace or bracket, an anchor or a literal. A lone brace or bracket is a literal in PCRE and
	 * an error in JavaScript.
	 *
	 * @param int                               $index Where the token starts
	 * @param array{group:bool,inner:bool}|null $last  What a quantifier here would repeat
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool}|null}|null
	 */
	private function otherToken(int $index, ?array $last): ?array {
		$char   = $this->pattern[$index];
		$length = $this->atoms->quantifierLength(pattern: $this->pattern, index: $index);
		if ($length > 0) {
			return $this->quantify(index: $index, length: $length, last: $last);
		}

		if (str_contains('{}]', $char) === true) {
			return null;
		}

		if ($char === '^' || $char === '$') {
			return [$index + 1, null];
		}

		return [$index + 1, self::ATOM];
	}//end otherToken()

	/**
	 * Apply a quantifier to what came before it. A possessive quantifier is PCRE only.
	 *
	 * @param int                               $index  Where the quantifier starts
	 * @param int                               $length Its length
	 * @param array{group:bool,inner:bool}|null $last   What it repeats
	 *
	 * @return array{0:int,1:null}|null
	 */
	private function quantify(int $index, int $length, ?array $last): ?array {
		$repeats = $this->pattern[$index] !== '?';
		if ($last === null || ($last['group'] === true && $last['inner'] === true && $repeats === true)) {
			return null;
		}

		$this->stack[array_key_last($this->stack)]['inner'] = true;
		$next   = $index + $length;
		$suffix = $this->pattern[$next] ?? '';
		if ($suffix === '+') {
			return null;
		}

		if ($suffix === '?') {
			$next++;
		}

		return [$next, null];
	}//end quantify()
}//end class
