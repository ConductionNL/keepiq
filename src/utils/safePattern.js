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
const ESCAPES = 'dDwWsSbBtnrf^$\\.*+?()[]{}|/'

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
	if (typeof pattern !== 'string' || pattern === '') {
		return 'invalid'
	}
	try {
		new RegExp(pattern, 'u')
	} catch {
		return 'invalid'
	}
	const fits = [...pattern].length <= MAX_PATTERN_LENGTH && !hasHugeBound(pattern) && scan(pattern)
	return fits ? '' : 'unsupported'
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
	const stack = [{ inner: false, look: false, alternation: false, choices: 0 }]
	let last = null
	let i = 0
	while (i < p.length) {
		const step = token(p, i, stack, last)
		if (step === null) {
			return false
		}
		i = step.next
		last = step.last
	}
	const anchored = p.startsWith('^') && !stack[0].alternation
	return stack.length === 1 && stack[0].choices + (anchored ? 0 : 1) <= MAX_DEGREE
}

/**
 * Read one token at `i`.
 *
 * @param {string} p The pattern.
 * @param {number} i Where the token starts.
 * @param {Array<{inner: boolean, look: boolean, alternation: boolean, choices?: number}>} stack The open groups, changed in place; the first counts the choice points.
 * @param {{group: boolean, inner: boolean}|null} last What a quantifier here would repeat.
 * @return {{next: number, last: object|null}|null} Where the next token starts, or null to refuse.
 */
function token(p, i, stack, last) {
	const c = p[i]
	const top = stack[stack.length - 1]
	const atom = (length) => (length === 0 ? null : { next: i + length, last: { group: false, inner: false } })
	if (c === '\\') {
		// A word boundary is an assertion: nothing to repeat.
		return 'bB'.includes(p[i + 1]) ? { next: i + 2, last: null } : atom(escapeLength(p, i, false))
	}
	if (c === '[') {
		return atom(classLength(p, i))
	}
	if (c === '(') {
		return openGroup(p, i, stack)
	}
	if (c === ')') {
		return closeGroup(i, stack)
	}
	if (c === '|') {
		top.inner = true
		if (!top.alternation) {
			top.alternation = true
			stack[0].choices += 1
		}
		return { next: i + 1, last: null }
	}
	const length = quantifierLength(p, i)
	if (length > 0) {
		return quantify(p, i, length, stack, last)
	}
	if ('{}]'.includes(c)) {
		// A lone brace or bracket is a literal in PCRE and an error in JavaScript.
		return null
	}
	return { next: i + 1, last: '^$'.includes(c) ? null : { group: false, inner: false } }
}

/**
 * Open a group: plain, non-capturing, named, or a lookahead.
 *
 * @param {string} p The pattern.
 * @param {number} i Where the `(` is.
 * @param {Array<object>} stack The open groups, changed in place.
 * @return {{next: number, last: null}|null}
 */
function openGroup(p, i, stack) {
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
 * @param {number} i Where the `)` is.
 * @param {Array<object>} stack The open groups, changed in place.
 * @return {{next: number, last: object|null}|null}
 */
function closeGroup(i, stack) {
	if (stack.length === 1) {
		return null
	}
	const closed = stack.pop()
	stack[stack.length - 1].inner ||= closed.inner
	// A lookahead cannot be repeated.
	return { next: i + 1, last: closed.look ? null : { group: true, inner: closed.inner } }
}

/**
 * Apply a quantifier to what came before it.
 *
 * @param {string} p The pattern.
 * @param {number} i Where the quantifier starts.
 * @param {number} length Its length.
 * @param {Array<{inner: boolean, choices?: number}>} stack The open groups; the last is the one it is in.
 * @param {{group: boolean, inner: boolean}|null} last What it repeats.
 * @return {{next: number, last: null}|null}
 */
function quantify(p, i, length, stack, last) {
	if (last === null) {
		return null
	}
	if (last.group && last.inner && p[i] !== '?') {
		return null
	}
	stack[stack.length - 1].inner = true
	if (isVariable(p.slice(i, i + length))) {
		stack[0].choices += 1
	}
	let next = i + length
	if (p[next] === '+') {
		// Possessive: PCRE only.
		return null
	}
	if (p[next] === '?') {
		next += 1
	}
	return { next, last: null }
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
	if (next === undefined) {
		return 0
	}
	if (next === 'x') {
		return /^[0-9a-fA-F]{2}$/.test(p.slice(i + 2, i + 4)) ? 4 : 0
	}
	if (inClass && (next === '-' || next === 'b' || next === 'B')) {
		return next === '-' ? 2 : 0
	}
	return ESCAPES.includes(next) ? 2 : 0
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
	let j = p[i + 1] === '^' ? i + 2 : i + 1
	if (p[j] === ']') {
		return 0
	}
	while (j < p.length) {
		if (p[j] === ']') {
			return j - i + 1
		}
		if (p[j] === '[') {
			return 0
		}
		const step = p[j] === '\\' ? escapeLength(p, j, true) : 1
		if (step === 0) {
			return 0
		}
		j += step
	}
	return 0
}
