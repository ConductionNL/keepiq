/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A requester's pattern runs on every keystroke in the recipient's browser,
 * so one that can backtrack for ever, or that PCRE and JavaScript read
 * differently, is refused. tests/Unit/Service/SafePatternTest.php checks the
 * same lists against the server's copy of the rules.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */

import { describe, expect, it } from 'vitest'
import { fitImportedBlob, schemaError } from '../../src/utils/fieldSchema.js'
import {
	isSafePattern,
	MAX_PATTERN_INPUT,
	patternProblem,
} from '../../src/utils/safePattern.js'

const SAFE = [
	'^[0-9]{4,6}$',
	'^[A-Z]{2}\\d{2}[A-Z0-9]{1,30}$',
	'^(ab)+$',
	'^(?:https?)://',
	'^(a+)?$',
	'(?<year>\\d{4})-\\d{2}',
	'^\\x41[\\-a-z]*$',
	'(?=.*\\d)\\w{8,}',
	'\\bfoo\\b',
	// Three choice points, anchored; two, unanchored; fixed counts are none.
	'^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$',
	'\\d+-\\d+',
	'^\\d{1,30}\\d{1,30}\\d{1,30}$',
	'^(a|b)(c|d)e?$',
	'^\\d{2}\\d{2,2}[a-z]+[a-z]*\\d?$',
	// 300 characters, though 600 UTF-16 units.
	'\u{1F600}'.repeat(300),
]

const UNSAFE = [
	// Backtracking that the old single check let through.
	'^((a+))+$',
	'^(a|a)+$',
	'^(a|aa)+$',
	'^(a+){10}$',
	'^(a+)+$',
	'(a?){5}',
	// Too many choice points: variable quantifiers and alternation groups.
	'^\\d*\\d*\\d*\\d*x$',
	'^.*.*.*.*.*.*.*.*=$',
	'\\d*\\d*\\d*x',
	'a|^\\d*\\d*\\d*x',
	'^\\d{0,99}\\d{0,99}\\d{0,99}\\d{0,99}x$',
	'^\\d{1,30}\\d{1,30}\\d{1,30}\\d{1,30}$',
	'^' + '(a|a)'.repeat(28) + '$',
	'^' + 'a{0,64}'.repeat(6) + '$',
	'^(a|b)(c|d)(e|f)(g|h)$',
	// A lookbehind, which PCRE refuses when its length varies.
	'(?<=a+)b',
	'^(?<=x*)y$',
	'(?<=a{1,3})b',
	// PCRE only.
	'(?i)abc',
	'a++b',
	'(?>a+)b',
	'\\Aabc\\z',
	'abc\\Z',
	'(?#c)a',
	'[[:alpha:]]',
	'a{',
	'(a)\\1',
	// Does not compile, or nothing to repeat.
	'^[0-9',
	'(?=a)+',
	'',
	'x'.repeat(501),
]

describe('safe patterns', () => {
	it.each(SAFE)('accepts %s', (pattern) => {
		expect(isSafePattern(pattern)).toBe(true)
	})

	it.each(UNSAFE)('refuses %s', (pattern) => {
		expect(isSafePattern(pattern)).toBe(false)
	})

	it('tells a pattern that does not compile from one it cannot use', () => {
		expect(patternProblem('^[0-9')).toBe('invalid')
		// Valid, but four choice points; and a bound PCRE does not compile.
		expect(patternProblem('^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$')).toBe(
			'unsupported',
		)
		expect(patternProblem('^x{0,70000}$')).toBe('unsupported')
		expect(patternProblem('^\\d{4}$')).toBe('')
	})

	it('never runs an unsafe pattern, and no value fits it', () => {
		const schema = { type: 'string', pattern: '^(a|aa)+$' }
		const started = Date.now()

		expect(schemaError('a'.repeat(40) + 'b', schema)).toBe(
			'This does not match the required pattern.',
		)
		expect(Date.now() - started).toBeLessThan(100)
	})

	it('does not test a pattern against a value longer than the cap', () => {
		const schema = { type: 'string', pattern: '^a*$' }

		expect(schemaError('a'.repeat(MAX_PATTERN_INPUT), schema)).toBe('')
		expect(schemaError('a'.repeat(MAX_PATTERN_INPUT + 1), schema)).toBe(
			`Use at most ${MAX_PATTERN_INPUT} characters.`,
		)
	})

	it('drops an imported unsafe pattern from an empty field, keeping the rest of its schema', () => {
		const blob = {
			Code: '',
			'@schema': {
				Code: {
					type: 'string',
					maxLength: 8,
					pattern: '^\\d*\\d*\\d*\\d*x$',
				},
			},
		}

		expect(fitImportedBlob(blob)).toEqual({
			Code: '',
			'@schema': { Code: { type: 'string', maxLength: 8 } },
		})
	})

	it('drops an imported unsafe pattern, keeping the value and its mask', () => {
		const blob = {
			PIN: '1234',
			'@schema': {
				PIN: { type: 'string', hidden: true, pattern: '(?i)\\d+' },
			},
		}

		expect(fitImportedBlob(blob)).toEqual({
			PIN: '1234',
			'@schema': { PIN: { type: 'string', hidden: true } },
		})
	})
})
