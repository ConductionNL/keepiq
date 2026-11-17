/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change to https from the Unencrypted address category: only the address is
 * saved, as https, and the vault is analysed again; a failed save says so and
 * analyses nothing.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#scenario-a-vault-user-finds-a-login-saved-with-http
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import HealthReportView from '../../src/views/HealthReportView.vue'

const { methods } = HealthReportView

/**
 * A fake instance with changeToHttps bound and reanalyse spied.
 *
 * @param {Function} updateSecret The secret store's updateSecret.
 * @return {object}
 */
function makeVm(updateSecret) {
	const vm = { secrets: { updateSecret }, httpsError: '', reanalyse: vi.fn().mockResolvedValue() }
	vm.changeToHttps = methods.changeToHttps.bind(vm)
	return vm
}

describe('HealthReportView changeToHttps', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('saves the address as https and analyses again', async () => {
		const updateSecret = vi.fn().mockResolvedValue({})
		const vm = makeVm(updateSecret)

		await vm.changeToHttps({ id: 's1', name: 'Intranet', url: 'http://intranet.example.org' })

		expect(updateSecret).toHaveBeenCalledWith('s1', { url: 'https://intranet.example.org' })
		expect(vm.reanalyse).toHaveBeenCalledOnce()
		expect(vm.httpsError).toBe('')
	})

	it('reports a failed save and analyses nothing', async () => {
		const vm = makeVm(vi.fn().mockRejectedValue(new Error('409')))

		await vm.changeToHttps({ id: 's1', name: 'Intranet', url: 'http://intranet.example.org' })

		expect(vm.reanalyse).not.toHaveBeenCalled()
		// The test t() returns the source string; the name is a placeholder of it.
		expect(vm.httpsError).toBe('Could not save the https address for {name}.')
	})
})
