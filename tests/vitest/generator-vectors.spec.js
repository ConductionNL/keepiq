/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Re-runs the shared generator cases in tests/vectors/generator/cases.json
 * with the web generator. The native apps' Kotlin generator runs the same
 * file (mobile/shared/src/commonTest GeneratorVectorsTest), so an option,
 * policy clamp or draw order that changes on one side only fails a suite.
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-send-and-generator/spec.md#requirement-generate-passwords-and-passphrases
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { generateKey, generatePassphrase } from '../../src/generator/generator.js'
import { seededRandom } from '../vectors/seeded-random.mjs'

const { cases } = JSON.parse(
	readFileSync(new URL('../vectors/generator/cases.json', import.meta.url), 'utf8'),
)

describe('shared generator cases', () => {
	it('holds cases for both kinds and for refusals', () => {
		expect(cases.length).toBeGreaterThan(100)
		expect(cases.some((c) => c.kind === 'passphrase' && c.expected)).toBe(true)
		expect(cases.some((c) => c.error)).toBe(true)
	})

	it.each(cases.map((c) => [`${c.name} (seed ${c.seed})`, c]))('%s', (_, c) => {
		const rand = seededRandom(c.seed)
		const run = () =>
			c.kind === 'passphrase'
				? generatePassphrase(c.options, c.policy, rand)
				: generateKey(c.options, c.policy, rand)
		if (c.error) {
			expect(run).toThrow(c.error)
		} else {
			expect(run()).toBe(c.expected)
		}
	})
})
