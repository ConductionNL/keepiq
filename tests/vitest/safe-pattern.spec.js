/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A requester's pattern runs on every keystroke in the recipient's browser,
 * so one that can backtrack for ever, or that PCRE and JavaScript read
 * differently, is refused. tests/Unit/Service/SafePatternTest.php checks the
 * same lists against the server's copy of the rules.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */

import { describe, expect, it } from 'vitest'
import { fitImportedBlob, schemaError } from '../../src/utils/fieldSchema.js'
import { isSafePattern, MAX_PATTERN_INPUT } from '../../src/utils/safePattern.js'

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
]

const UNSAFE = [
	// Backtracking that the old single check let through.
	'^((a+))+$',
	'^(a|a)+$',
	'^(a|aa)+$',
	'^(a+){10}$',
	'^(a+)+$',
	'(a?){5}',
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

	it('drops an imported unsafe pattern, keeping the value and its mask', () => {
		const blob = {
			PIN: '1234',
			'@schema': { PIN: { type: 'string', hidden: true, pattern: '(?i)\\d+' } },
		}

		expect(fitImportedBlob(blob)).toEqual({
			PIN: '1234',
			'@schema': { PIN: { type: 'string', hidden: true } },
		})
	})
})
