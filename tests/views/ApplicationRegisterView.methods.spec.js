/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the logic of `src/views/ApplicationRegisterView.vue`:
 * the client-side name filter, the status label and badge variant maps, the
 * `?action=register` deep link (opens the dialog and strips only that query
 * key), opening an application, and the refresh after a registration.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApplicationRegisterView from '../../src/views/ApplicationRegisterView.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const APPS = [
	{ id: 'a1', name: 'CI Runner', status: 'active' },
	{ id: 'a2', name: 'Backup', status: 'pending' },
	{ id: 'a3', status: 'rejected' },
]

/**
 * Mount the view with the application list answered by the stubbed HTTP
 * client and a router spy.
 *
 * @param {object} query The route query.
 * @return {Promise<{wrapper: object, router: object, get: Function}>} The mount.
 */
async function mountView(query = {}) {
	const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: APPS })
	const router = { push: vi.fn(), replace: vi.fn() }
	const wrapper = mount(ApplicationRegisterView, {
		global: { mocks: { $route: { query }, $router: router } },
	})
	await flush()
	return { wrapper, router, get }
}

describe('ApplicationRegisterView — logic', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('rows', () => {
		it('returns every application without a search term', async () => {
			const { wrapper } = await mountView()
			expect(wrapper.vm.rows.map((a) => a.id)).toEqual(['a1', 'a2', 'a3'])
		})

		it('filters case-insensitively on a trimmed name term', async () => {
			const { wrapper } = await mountView()
			wrapper.vm.onSearch('  ci RUN ')
			expect(wrapper.vm.searchTerm).toBe('  ci RUN ')
			expect(wrapper.vm.rows.map((a) => a.id)).toEqual(['a1'])
		})

		it('skips nameless applications when filtering', async () => {
			const { wrapper } = await mountView()
			wrapper.vm.onSearch('a')
			expect(wrapper.vm.rows.map((a) => a.id)).toEqual(['a2'])
		})

		it('treats a missing list as empty', async () => {
			const { wrapper } = await mountView()
			wrapper.vm.store.applications = null
			expect(wrapper.vm.rows).toEqual([])
		})
	})

	it('describes the list fields', async () => {
		const { wrapper } = await mountView()
		expect(Object.keys(wrapper.vm.listSchema.properties)).toEqual([
			'name',
			'description',
		])
		expect(wrapper.vm.listSchema.configuration).toEqual({
			objectNameField: 'name',
			objectDescriptionField: 'description',
		})
		expect(wrapper.vm.listConfig).toEqual({
			titleField: 'name',
			subtitleField: 'description',
		})
	})

	it('labels each status, passing an unknown one through', async () => {
		const { wrapper } = await mountView()
		expect(wrapper.vm.statusLabel('active')).toBe('Active')
		expect(wrapper.vm.statusLabel('pending')).toBe('Pending approval')
		expect(wrapper.vm.statusLabel('rejected')).toBe('Rejected')
		expect(wrapper.vm.statusLabel('suspended')).toBe('suspended')
		expect(wrapper.vm.statusLabel(undefined)).toBe('')
	})

	it('maps each status to a badge variant', async () => {
		const { wrapper } = await mountView()
		expect(wrapper.vm.statusVariant('active')).toBe('success')
		expect(wrapper.vm.statusVariant('pending')).toBe('warning')
		expect(wrapper.vm.statusVariant('rejected')).toBe('error')
		expect(wrapper.vm.statusVariant('other')).toBe('default')
	})

	describe('register deep link', () => {
		it('opens the dialog and strips only the action marker', async () => {
			const { wrapper, router } = await mountView({
				action: 'register',
				tab: 'mine',
			})
			expect(wrapper.vm.dialogOpen).toBe(true)
			expect(router.replace).toHaveBeenCalledWith({ query: { tab: 'mine' } })
		})

		it('ignores any other action', async () => {
			const { wrapper, router } = await mountView({ action: 'delete' })
			expect(wrapper.vm.dialogOpen).toBe(false)
			expect(router.replace).not.toHaveBeenCalled()
		})
	})

	it('opens the clicked application detail', async () => {
		const { wrapper, router } = await mountView()
		wrapper.vm.openApplication({ id: 'a2' })
		expect(router.push).toHaveBeenCalledWith('/applications/a2')
	})

	it('closes the dialog and reloads the list after registering', async () => {
		const { wrapper, get } = await mountView()
		wrapper.vm.dialogOpen = true
		const fetch = vi.spyOn(wrapper.vm.store, 'fetchApplications').mockResolvedValue()

		wrapper.vm.onRegistered()

		expect(wrapper.vm.dialogOpen).toBe(false)
		expect(fetch).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('swallows a failed reload after registering', async () => {
		const { wrapper } = await mountView()
		vi.spyOn(wrapper.vm.store, 'fetchApplications').mockRejectedValue(
			new Error('offline'),
		)
		expect(() => wrapper.vm.onRegistered()).not.toThrow()
		await flush()
		expect(wrapper.vm.dialogOpen).toBe(false)
	})
})
