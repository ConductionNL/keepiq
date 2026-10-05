/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Re-runs the shared autofill cases in tests/vectors/autofill/cases.json
 * with the browser extension's matcher, use-only rules and save
 * classifier. The native apps' system autofill runs the same file
 * (mobile/shared/src/commonTest AutofillVectorsTest), so a rule that
 * changes on one side only fails a suite.
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-system-autofill/spec.md#requirement-keepiq-as-the-autofill-service-on-android
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
	hostOf,
	isPublicSuffix,
	matchScore,
	matchSecrets,
	registrableDomain,
} from '../../browser-extension/src/lib/match.js'
import { blocksSavePrompt, filterForHost } from '../../browser-extension/src/lib/useOnly.js'
import { classifyCapture } from '../../browser-extension/src/lib/capture.js'

const cases = JSON.parse(
	readFileSync(new URL('../vectors/autofill/cases.json', import.meta.url), 'utf8'),
)

describe('shared autofill cases', () => {
	it.each(cases.hosts.map((h) => [JSON.stringify(h.input), h]))('host %s', (_, h) => {
		expect(hostOf(h.input)).toBe(h.host)
		expect(registrableDomain(h.input)).toBe(h.registrable)
		expect(isPublicSuffix(h.input)).toBe(h.publicSuffix)
	})

	it('scores', () => {
		for (const s of cases.scores) {
			expect(matchScore({ name: s.name, url: s.url }, s.target)).toBe(s.score)
		}
	})

	it('ranking and use-only rules', () => {
		for (const m of cases.matches) {
			const ranked = matchSecrets(cases.items, m.target)
			expect(ranked.map((r) => r.id)).toEqual(m.ids)
			expect(filterForHost(ranked, m.target).map((r) => r.id)).toEqual(m.filtered)
			expect(blocksSavePrompt(cases.items, m.target)).toBe(m.blocksSave)
		}
	})

	it('save offers', async () => {
		const decrypt = async (row) => {
			const found = cases.stored.find((s) => s.id === row.id)
			if (!found.plain) throw new Error('cannot open')
			return found.plain
		}
		for (const c of cases.classify) {
			const offer = await classifyCapture(c, cases.stored, decrypt)
			expect(offer.action).toBe(c.action)
			if (c.action === 'update') expect(offer.id).toBe(c.id)
		}
	})
})
