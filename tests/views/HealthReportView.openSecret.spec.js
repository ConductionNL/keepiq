/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Clicking a health finding opens the secret (keepiq#745).
 *
 * The view pushed a route named SecretDetail, which no page declares, and an
 * empty catch hid the failure. The detail lives on the vault list page,
 * /secrets/:id, whose route is SecretList; the push must name a route the
 * manifest really has.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import HealthReportView from '../../src/views/HealthReportView.vue'

describe('HealthReportView: a finding opens its secret (keepiq#745)', () => {
	it('pushes a route the manifest declares, with the secret id', () => {
		const push = vi.fn().mockResolvedValue()
		HealthReportView.methods.openSecret.call({ $router: { push } }, 'sec-42')

		expect(push).toHaveBeenCalledTimes(1)
		const location = push.mock.calls[0][0]
		expect(location).toEqual({ name: 'SecretList', params: { id: 'sec-42' } })

		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === location.name)
		expect(page).toBeDefined()
		expect(page.route).toBe('/secrets/:id?')
	})
})
