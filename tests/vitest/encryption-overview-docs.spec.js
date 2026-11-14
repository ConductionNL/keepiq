/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The generated docs page lists the same values as the app: the committed
 * docs/encryption-overview.md equals what the generator renders from
 * src/crypto/parameters.js, and the rendered page carries every technical
 * name the settings overview shows.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { PAGE_PATH, renderOverview } from '../../docs/scripts/encryption-overview.mjs'
import { CRYPTO_PARAMETERS, technicalNames } from '../../src/crypto/parameters.js'

describe('docs/encryption-overview.md', () => {
	it('is the generator output for the current crypto parameters', () => {
		expect(readFileSync(PAGE_PATH, 'utf8')).toBe(renderOverview())
	})

	it('lists every technical name the app shows', () => {
		const page = renderOverview()
		for (const name of Object.values(technicalNames())) {
			expect(page).toContain(name)
		}
	})

	it('follows a changed parameter', () => {
		const page = renderOverview({
			...CRYPTO_PARAMETERS,
			privateKey: { ...CRYPTO_PARAMETERS.privateKey, iterations: 1200000 },
		})
		expect(page).toContain('PBKDF2-SHA256, 1,200,000 iterations')
		expect(page).not.toContain('600,000')
	})
})
