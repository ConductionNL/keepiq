/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The application secrets panel lists an active application's secrets and
 * shows nothing for an inactive one.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.5
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const applicationStore = { listApplicationSecrets: vi.fn() }

vi.mock('../../src/store/modules/application.js', () => ({
	useApplicationStore: () => applicationStore,
}))

const { default: Panel } = await import(
	'../../src/components/application/ApplicationSecretsPanel.vue'
)

/**
 * A fake instance with refresh bound to it.
 *
 * @param {object} overrides Fields to set.
 * @return {object} The instance.
 */
function makeVm(overrides = {}) {
	const vm = {
		...Panel.data(),
		applicationId: 'app-1',
		applicationActive: true,
		store: applicationStore,
		t: (_app, key) => key,
		...overrides,
	}
	vm.refresh = Panel.methods.refresh.bind(vm)
	return vm
}

describe('ApplicationSecretsPanel', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		applicationStore.listApplicationSecrets = vi.fn()
	})

	it('reads the application store', () => {
		expect(Panel.computed.store.call({})).toBe(applicationStore)
	})

	it('refreshes immediately and on every application change', () => {
		const vm = { refresh: vi.fn() }

		Panel.watch.applicationId.handler.call(vm)

		expect(Panel.watch.applicationId.immediate).toBe(true)
		expect(vm.refresh).toHaveBeenCalledTimes(1)
	})

	it('lists the secrets of an active application', async () => {
		const secrets = [{ name: 'db' }]
		applicationStore.listApplicationSecrets.mockResolvedValue(secrets)
		const vm = makeVm({ error: 'old' })

		await vm.refresh()

		expect(applicationStore.listApplicationSecrets).toHaveBeenCalledWith('app-1')
		expect(vm.secrets).toBe(secrets)
		expect(vm.error).toBe('')
		expect(vm.loading).toBe(false)
	})

	it('clears the list without a request for an inactive application', async () => {
		const vm = makeVm({ applicationActive: false, secrets: [{ name: 'x' }] })

		await vm.refresh()

		expect(vm.secrets).toEqual([])
		expect(applicationStore.listApplicationSecrets).not.toHaveBeenCalled()
	})

	it('clears the list without a request when there is no application id', async () => {
		const vm = makeVm({ applicationId: '', secrets: [{ name: 'x' }] })

		await vm.refresh()

		expect(vm.secrets).toEqual([])
		expect(applicationStore.listApplicationSecrets).not.toHaveBeenCalled()
	})

	it('prefers the server message, then the error message, then a generic one', async () => {
		const vm = makeVm()

		applicationStore.listApplicationSecrets.mockRejectedValueOnce({
			response: { data: { message: 'Forbidden' } },
			message: 'Request failed',
		})
		await vm.refresh()
		expect(vm.error).toBe('Forbidden')
		expect(vm.loading).toBe(false)

		applicationStore.listApplicationSecrets.mockRejectedValueOnce(new Error('offline'))
		await vm.refresh()
		expect(vm.error).toBe('offline')

		applicationStore.listApplicationSecrets.mockRejectedValueOnce({})
		await vm.refresh()
		expect(vm.error).toBe('Failed to load secrets')
	})

	it('formats a date and passes an empty or unformattable one through', () => {
		const iso = '2026-02-03T04:05:06Z'
		expect(Panel.methods.formatDate(iso)).toBe(new Date(iso).toLocaleDateString())
		expect(Panel.methods.formatDate('')).toBe('')
		expect(Panel.methods.formatDate(null)).toBe('')

		vi.spyOn(Date.prototype, 'toLocaleDateString').mockImplementation(() => {
			throw new RangeError('bad')
		})
		expect(Panel.methods.formatDate('2026-01-01')).toBe('2026-01-01')
	})
})
