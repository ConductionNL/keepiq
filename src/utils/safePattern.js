/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a field schema's `pattern` is safe to run on every keystroke and
 * reads the same in JavaScript and in PHP's PCRE. A requester sets it, and the
 * recipient's browser runs it with no time limit, so a pattern that can
 * backtrack for ever is refused, and so is any construct only one of the two
 * dialects reads. A failed match tries every combination of its choice points,
 * each variable quantifier and each group holding an alternation, so their
 * count, plus one when the pattern is not anchored with `^`, is held to
 * MAX_DEGREE and the value to MAX_PATTERN_INPUT, which keeps a test to
 * milliseconds.
 * lib/Service/SafePattern.php holds the same rules.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */

/** The longest pattern a schema may carry. */
export const MAX_PATTERN_LENGTH = 500

/** The longest value a pattern is tested against. */
export const MAX_PATTERN_INPUT = 256

/** The most choice points, plus one for a pattern not anchored with `^`. */
const MAX_DEGREE = 3

/** Escaped characters that read alike in both dialects, outside a class. */
const ESCAPES = new Set('dDwWsSbBtnrf^$\\.*+?()[]{}|/')

/** Inside a class, `\-` is a hyphen, and `\b` and `\B` read differently. */
const CLASS_ESCAPES = new Map([
	['-', 2],
	['b', 0],
	['B', 0],
])

/**
 * Whether a pattern is safe: not too long, compiles, and passes the scan.
 *
 * @param {unknown} pattern The pattern.
 * @return {boolean}
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
export function isSafePattern(pattern) {
	return patternProblem(pattern) === ''
}

/**
 * What keeps a pattern from being used: `invalid` when it is no regular
 * expression, `unsupported` when it is one that could take too long to check
 * or that the server reads differently, '' when it can be used.
 *
 * @param {unknown} pattern The pattern.
 * @return {''|'invalid'|'unsupported'}
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
export function patternProblem(pattern) {
	if (!compiles(pattern)) {
		return 'invalid'
	}
	return fits(pattern) ? '' : 'unsupported'
}

/**
 * Whether a pattern is a non-empty string that compiles.
 *
 * @param {unknown} pattern The pattern.
 * @return {boolean}
 */
function compiles(pattern) {
	if (typeof pattern !== 'string' || pattern === '') {
		return false
	}
	try {
		new RegExp(pattern, 'u')
		return true
	} catch {
		return false
	}
}

/**
 * Whether a compiling pattern is short enough, has no bound PCRE refuses,
 * and passes the scan.
 *
 * @param {string} pattern The pattern.
 * @return {boolean}
 */
function fits(pattern) {
	return (
		[...pattern].length <= MAX_PATTERN_LENGTH
		&& !hasHugeBound(pattern)
		&& scan(pattern)
	)
}

/**
 * Whether a counted quantifier has a bound above 65535, which JavaScript
 * accepts and PCRE does not compile.
 *
 * @param {string} pattern The pattern.
 * @return {boolean}
 */
function hasHugeBound(pattern) {
	return [...pattern.matchAll(/\{(\d+)(?:,(\d*))?\}/g)].some(
		([, low, high]) => Number(low) > 65535 || Number(high || 0) > 65535,
	)
}

/**
 * Walk the pattern once. A repeated group must hold no quantifier and no
 * alternation at any depth, since that is what backtracks for ever, as in
 * `(a+)+`, `(a|aa)+` or `(a+){10}`. `?` repeats nothing, so it may follow
 * such a group.
 *
 * @param {string} p The pattern.
 * @return {boolean} True when nothing unsafe was found.
 */
function scan(p) {
	const scanner = {
		p,
		stack: [{ inner: false, look: false, alternation: false, choices: 0 }],
		last: null,
	}
	let i = 0
	while (i < p.length) {
		const step = token(scanner, i)
		if (step === null) {
			return false
		}
		i = step.next
		scanner.last = step.last
	}
	return withinDegree(scanner)
}

/**
 * Whether a scanned pattern closed every group and holds no more choice
 * points than MAX_DEGREE allows.
 *
 * @param {{p: string, stack: Array<object>}} scanner The finished scan.
 * @return {boolean}
 */
function withinDegree({ p, stack }) {
	const anchored = p.startsWith('^') && !stack[0].alternation
	return stack.length === 1 && stack[0].choices + (anchored ? 0 : 1) <= MAX_DEGREE
}

/**
 * The reader of each token that starts with a character of its own.
 */
const TOKEN_READERS = new Map([
	['\\', readEscape],
	['[', readClass],
	['(', openGroup],
	[')', closeGroup],
	['|', alternate],
])

/**
 * Read one token at `i`.
 *
 * The scanner holds the pattern `p`, the open groups `stack` (changed in
 * place; the first counts the choice points) and `last`, what a quantifier
 * here would repeat.
 *
 * @param {{p: string, stack: Array<{inner: boolean, look: boolean, alternation: boolean, choices?: number}>, last: {group: boolean, inner: boolean}|null}} scanner The scan so far.
 * @param {number} i Where the token starts.
 * @return {{next: number, last: object|null}|null} Where the next token starts, or null to refuse.
 */
function token(scanner, i) {
	const read = TOKEN_READERS.get(scanner.p[i])
	if (read) {
		return read(scanner, i)
	}
	const length = quantifierLength(scanner.p, i)
	if (length > 0) {
		return quantify(scanner, i, length)
	}
	return literal(scanner.p[i], i)
}

/**
 * An atom of `length` characters at `i`, null when there is none.
 *
 * @param {number} i Where it starts.
 * @param {number} length Its length.
 * @return {{next: number, last: {group: boolean, inner: boolean}}|null}
 */
function atom(i, length) {
	return length === 0
		? null
		: { next: i + length, last: { group: false, inner: false } }
}

/**
 * Read an escape. A word boundary is an assertion: nothing to repeat.
 *
 * @param {{p: string}} scanner The scan so far.
 * @param {number} i Where the `\` is.
 * @return {{next: number, last: object|null}|null}
 */
function readEscape({ p }, i) {
	return 'bB'.includes(p[i + 1])
		? { next: i + 2, last: null }
		: atom(i, escapeLength(p, i, false))
}

/**
 * Read a character class.
 *
 * @param {{p: string}} scanner The scan so far.
 * @param {number} i Where the `[` is.
 * @return {{next: number, last: object}|null}
 */
function readClass({ p }, i) {
	return atom(i, classLength(p, i))
}

/**
 * Read a `|`, counting the group it is in as a choice point once.
 *
 * @param {{stack: Array<object>}} scanner The scan so far.
 * @param {number} i Where the `|` is.
 * @return {{next: number, last: null}}
 */
function alternate({ stack }, i) {
	const top = stack[stack.length - 1]
	top.inner = true
	if (!top.alternation) {
		top.alternation = true
		stack[0].choices += 1
	}
	return { next: i + 1, last: null }
}

/**
 * Read a literal character; an anchor repeats nothing.
 *
 * @param {string} c The character.
 * @param {number} i Where it is.
 * @return {{next: number, last: object|null}|null}
 */
function literal(c, i) {
	if ('{}]'.includes(c)) {
		// A lone brace or bracket is a literal in PCRE and an error in JavaScript.
		return null
	}
	return {
		next: i + 1,
		last: '^$'.includes(c) ? null : { group: false, inner: false },
	}
}

/**
 * Open a group: plain, non-capturing, named, or a lookahead.
 *
 * @param {{p: string, stack: Array<object>}} scanner The scan so far; its open groups are changed in place.
 * @param {number} i Where the `(` is.
 * @return {{next: number, last: null}|null}
 */
function openGroup({ p, stack }, i) {
	let length = 1
	let look = false
	if (p[i + 1] === '?') {
		const rest = p.slice(i + 2)
		const named = /^<[A-Za-z_][A-Za-z0-9_]*>/.exec(rest)
		if (rest.startsWith(':')) {
			length = 3
		} else if (/^[=!]/.test(rest)) {
			look = true
			length = 3
		} else if (named) {
			length = 2 + named[0].length
		} else {
			// Inline flags, atomic groups, comments and the like are PCRE only;
			// PCRE refuses a lookbehind of variable length, which JavaScript runs.
			return null
		}
	}
	stack.push({ inner: false, look, alternation: false })
	return { next: i + length, last: null }
}

/**
 * Close a group, passing what it holds on to the group around it.
 *
 * @param {{stack: Array<object>}} scanner The scan so far; its open groups are changed in place.
 * @param {number} i Where the `)` is.
 * @return {{next: number, last: object|null}|null}
 */
function closeGroup({ stack }, i) {
	if (stack.length === 1) {
		return null
	}
	const closed = stack.pop()
	stack[stack.length - 1].inner ||= closed.inner
	// A lookahead cannot be repeated.
	return {
		next: i + 1,
		last: closed.look ? null : { group: true, inner: closed.inner },
	}
}

/**
 * Apply a quantifier to what came before it: `last`, in the group that is
 * last on `stack`.
 *
 * @param {{p: string, stack: Array<{inner: boolean, choices?: number}>, last: {group: boolean, inner: boolean}|null}} scanner The scan so far.
 * @param {number} i Where the quantifier starts.
 * @param {number} length Its length.
 * @return {{next: number, last: null}|null}
 */
function quantify(scanner, i, length) {
	const { p, stack, last } = scanner
	const quantifier = p.slice(i, i + length)
	if (!canRepeat(last, quantifier)) {
		return null
	}
	stack[stack.length - 1].inner = true
	if (isVariable(quantifier)) {
		stack[0].choices += 1
	}
	return afterQuantifier(p, i + length)
}

/**
 * Whether a quantifier may repeat what came before it. `?` repeats nothing,
 * so it may follow a group that holds a quantifier or an alternation.
 *
 * @param {{group: boolean, inner: boolean}|null} last What it repeats.
 * @param {string} quantifier The quantifier.
 * @return {boolean}
 */
function canRepeat(last, quantifier) {
	return last !== null && !(last.group && last.inner && quantifier !== '?')
}

/**
 * Where the token after a quantifier starts: past a lazy `?`, or null for a
 * possessive `+`, which is PCRE only.
 *
 * @param {string} p The pattern.
 * @param {number} next The position right after the quantifier.
 * @return {{next: number, last: null}|null}
 */
function afterQuantifier(p, next) {
	if (p[next] === '+') {
		return null
	}
	return { next: p[next] === '?' ? next + 1 : next, last: null }
}

/**
 * Whether a quantifier is a choice point: `?`, `*`, `+`, `{m,}`, or `{m,n}`
 * with m < n. A fixed `{n}` is not.
 *
 * @param {string} quantifier The quantifier.
 * @return {boolean}
 */
function isVariable(quantifier) {
	const braces = /^\{(\d+)(?:,(\d*))?\}$/.exec(quantifier)
	if (braces === null) {
		return true
	}
	const [, low, high] = braces
	return high !== undefined && (high === '' || Number(low) < Number(high))
}

/**
 * The length of a quantifier at `i`, 0 when there is none.
 *
 * @param {string} p The pattern.
 * @param {number} i The position.
 * @return {number}
 */
function quantifierLength(p, i) {
	if ('*+?'.includes(p[i])) {
		return 1
	}
	const braces = /^\{\d+(?:,\d*)?\}/.exec(p.slice(i))
	return braces ? braces[0].length : 0
}

/**
 * The length of an escape at `i`, 0 when it is not one both dialects read
 * alike. Back-references are refused: they read differently and backtrack.
 *
 * @param {string} p The pattern.
 * @param {number} i Where the `\` is.
 * @param {boolean} inClass Whether it is inside a character class.
 * @return {number}
 */
function escapeLength(p, i, inClass) {
	const next = p[i + 1]
	if (next === 'x') {
		return hexEscapeLength(p, i)
	}
	if (inClass && CLASS_ESCAPES.has(next)) {
		return CLASS_ESCAPES.get(next)
	}
	return ESCAPES.has(next) ? 2 : 0
}

/**
 * The length of a `\x` escape at `i`: 4 with two hex digits, else 0.
 *
 * @param {string} p The pattern.
 * @param {number} i Where the `\` is.
 * @return {number}
 */
function hexEscapeLength(p, i) {
	return /^[0-9a-fA-F]{2}$/.test(p.slice(i + 2, i + 4)) ? 4 : 0
}

/**
 * The length of a character class at `i`, 0 when it is not one both
 * dialects read alike: `[]` and `[^]`, or a `[` inside, such as a POSIX class.
 *
 * @param {string} p The pattern.
 * @param {number} i Where the `[` is.
 * @return {number}
 */
function classLength(p, i) {
	const start = p[i + 1] === '^' ? i + 2 : i + 1
	if (p[start] === ']') {
		return 0
	}
	const end = classEnd(p, start)
	return end === -1 ? 0 : end - i + 1
}

/**
 * Where a class whose members start at `j` closes, -1 when it does not
 * close or holds a member both dialects do not read alike.
 *
 * @param {string} p The pattern.
 * @param {number} j Where its members start.
 * @return {number} The position of the `]`, or -1.
 */
function classEnd(p, j) {
	while (j < p.length && p[j] !== ']') {
		const step = classMemberLength(p, j)
		if (step === 0) {
			return -1
		}
		j += step
	}
	return j < p.length ? j : -1
}

/**
 * The length of one class member at `j`, 0 for a `[` or an escape both
 * dialects do not read alike.
 *
 * @param {string} p The pattern.
 * @param {number} j Where the member is.
 * @return {number}
 */
function classMemberLength(p, j) {
	if (p[j] === '[') {
		return 0
	}
	return p[j] === '\\' ? escapeLength(p, j, true) : 1
}
