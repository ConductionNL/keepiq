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
 * nothing, so it may follow such a group. A failed match tries every combination of its choice points, each variable
 * quantifier and each group holding an alternation, so their count, plus one when the pattern is not anchored with
 * `^`, is held to MAX_DEGREE, and the browser tests at most 256 characters, which keeps a test to milliseconds. PCRE
 * writes a counted group out once per repeat, so the pattern written out that way is held to MAX_WRITTEN_OUT.
 * src/utils/safePattern.js holds the same rules for the browser.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
final class SafePattern {
	/**
	 * The longest pattern a schema may carry.
	 *
	 * @var integer
	 */
	public const MAX_LENGTH = 500;

	/**
	 * The most choice points, plus one for a pattern not anchored with `^`.
	 *
	 * @var integer
	 */
	private const MAX_DEGREE = 3;

	/**
	 * The longest a pattern may be, in code points, with each counted group written out as often as it repeats. PCRE
	 * compiles a counted group once per repeat into at most 64 KiB, and no code point compiles to more than 11 bytes,
	 * so this stays well inside it.
	 *
	 * @var integer
	 */
	private const MAX_WRITTEN_OUT = 4096;

	/**
	 * What a quantifier repeats: a plain atom.
	 *
	 * @var array{group:bool,inner:bool}
	 */
	private const ATOM = ['group' => false, 'inner' => false];

	/**
	 * The open groups: whether each holds a quantifier or alternation, whether it is a lookahead, whether it holds
	 * an alternation itself, and where it starts. The first is the pattern outside every group.
	 *
	 * @var list<array{inner:bool,look:bool,alt:bool,start:int}>
	 */
	private array $stack = [];

	/**
	 * The group names seen so far. PCRE refuses a name used twice, which JavaScript accepts in separate alternatives.
	 *
	 * @var array<string,true>
	 */
	private array $names = [];

	/**
	 * The pattern's length in code points with each counted group written out as often as it repeats.
	 *
	 * @var integer
	 */
	private int $writtenOut = 0;

	/**
	 * The pattern being scanned.
	 *
	 * @var string
	 */
	private string $pattern = '';

	/**
	 * How many choice points the pattern has.
	 *
	 * @var integer
	 */
	private int $choices = 0;

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
	 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
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
		$this->pattern    = $pattern;
		$this->stack      = [['inner' => false, 'look' => false, 'alt' => false, 'start' => 0]];
		$this->choices    = 0;
		$this->names      = [];
		$this->writtenOut = mb_strlen($pattern);
		$last  = null;
		$index = 0;
		while ($index < strlen($pattern)) {
			$step = $this->token(index: $index, last: $last);
			if ($step === null) {
				return false;
			}

			[$index, $last] = $step;
		}

		return count($this->stack) === 1 && $this->withinDegree() === true && $this->writtenOut <= self::MAX_WRITTEN_OUT;
	}//end scan()

	/**
	 * Whether the choice points, plus one for a pattern not anchored with `^`, stay within MAX_DEGREE. An alternation
	 * outside every group undoes a leading `^`.
	 *
	 * @return bool
	 */
	private function withinDegree(): bool {
		$anchored = str_starts_with($this->pattern, '^') === true && $this->stack[0]['alt'] === false;

		return $this->choices + (int)($anchored === false) <= self::MAX_DEGREE;
	}//end withinDegree()

	/**
	 * Read one token.
	 *
	 * @param int                               $index Where the token starts
	 * @param array{group:bool,inner:bool,start?:int}|null $last  What a quantifier here would repeat
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool,start?:int}|null}|null Where the next token starts and what it may repeat, or null to refuse
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
	 * @return array{0:int,1:array{group:bool,inner:bool,start?:int}|null}|null
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
	 * Open a group: plain, non-capturing, named, or a lookahead. Inline flags, atomic groups, comments and the like
	 * are PCRE only, and a lookbehind is refused, as are a name over 32 characters and a name used before.
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

		$name = $opening['name'];
		if ($name !== null) {
			if (isset($this->names[$name]) === true) {
				return null;
			}

			$this->names[$name] = true;
		}

		$this->stack[] = ['inner' => false, 'look' => $opening['look'], 'alt' => false, 'start' => $index];
		return [$index + $opening['length'], null];
	}//end openGroup()

	/**
	 * Close a group, passing what it holds on to the group around it. A lookahead cannot be repeated.
	 *
	 * @param int $index Where the `)` is
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool,start?:int}|null}|null
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

		return [$index + 1, ['group' => true, 'inner' => $closed['inner'], 'start' => $closed['start']]];
	}//end closeGroup()

	/**
	 * An alternation: the group it is in now holds one, which is a choice point once per group.
	 *
	 * @param int $index Where the `|` is
	 *
	 * @return array{0:int,1:null}
	 */
	private function alternate(int $index): array {
		$top = array_key_last($this->stack);
		$this->stack[$top]['inner'] = true;
		if ($this->stack[$top]['alt'] === false) {
			$this->stack[$top]['alt'] = true;
			$this->choices++;
		}

		return [$index + 1, null];
	}//end alternate()

	/**
	 * A quantifier, a lone brace or bracket, an anchor or a literal. A lone brace or bracket is a literal in PCRE and
	 * an error in JavaScript.
	 *
	 * @param int                               $index Where the token starts
	 * @param array{group:bool,inner:bool,start?:int}|null $last  What a quantifier here would repeat
	 *
	 * @return array{0:int,1:array{group:bool,inner:bool,start?:int}|null}|null
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
	 * @param array{group:bool,inner:bool,start?:int}|null $last   What it repeats
	 *
	 * @return array{0:int,1:null}|null
	 */
	private function quantify(int $index, int $length, ?array $last): ?array {
		$repeats = $this->pattern[$index] !== '?';
		if ($last === null || ($last['group'] === true && $last['inner'] === true && $repeats === true)) {
			return null;
		}

		$this->stack[array_key_last($this->stack)]['inner'] = true;
		$quantifier = substr($this->pattern, $index, $length);
		if ($this->atoms->isVariable(quantifier: $quantifier) === true) {
			$this->choices++;
		}

		$this->writeOut(index: $index, last: $last, quantifier: $quantifier);
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

	/**
	 * Count a counted group as written out as often as the quantifier repeats it.
	 *
	 * @param int                                     $index      Where the quantifier starts
	 * @param array{group:bool,inner:bool,start?:int} $last       What it repeats
	 * @param string                                  $quantifier The quantifier
	 *
	 * @return void
	 */
	private function writeOut(int $index, array $last, string $quantifier): void {
		if ($last['group'] === false) {
			return;
		}

		$start = $last['start'] ?? 0;
		$this->writtenOut += ($this->atoms->copies(quantifier: $quantifier) - 1) * mb_strlen(
			substr($this->pattern, $start, $index - $start)
		);
	}//end writeOut()
}//end class
