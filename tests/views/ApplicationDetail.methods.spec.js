/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The application detail view: loading, the certificate section, status
 * labels, navigation and delete. Stores and the router are faked.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.2
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const settingsStore = { holdsArea: vi.fn() }
const applicationStore = {}

vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => settingsStore,
}))
vi.mock('../../src/store/modules/application.js', () => ({
	useApplicationStore: () => applicationStore,
}))

const { default: ApplicationDetail } = await import(
	'../../src/views/ApplicationDetail.vue'
)
const { computed, methods, watch } = ApplicationDetail

const t = (_app, key) => key

/**
 * A fake instance with the view's methods bound to it.
 *
 * @param {object} overrides Fields to set on the instance.
 * @return {object} The instance.
 */
function makeVm(overrides = {}) {
	const vm = {
		...ApplicationDetail.data(),
		t,
		store: {
			currentApplication: { id: 'app-1', status: 'active' },
			fetchApplication: vi.fn().mockResolvedValue(),
			fetchCertificate: vi.fn().mockResolvedValue('PEM'),
			deleteApplication: vi.fn().mockResolvedValue(),
		},
		$router: { push: vi.fn() },
		...overrides,
	}
	vm.application = vm.store.currentApplication
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('ApplicationDetail', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('computed', () => {
		it('reads the store and its current application', () => {
			applicationStore.currentApplication = { id: 'x' }
			const store = computed.store.call({})

			expect(store).toBe(applicationStore)
			expect(computed.application.call({ store })).toEqual({ id: 'x' })
		})

		it('treats only an explicit true from holdsArea as admin', () => {
			settingsStore.holdsArea.mockReturnValueOnce(true)
			expect(computed.isAdmin.call({})).toBe(true)
			expect(settingsStore.holdsArea).toHaveBeenLastCalledWith('applications')

			settingsStore.holdsArea.mockReturnValueOnce('yes')
			expect(computed.isAdmin.call({})).toBe(false)
		})

		it('labels known statuses and passes unknown ones through', () => {
			const label = (status) =>
				computed.statusLabel.call({ t, application: { status } })

			expect(label('active')).toBe('Active')
			expect(label('pending')).toBe('Pending')
			expect(label('revoked')).toBe('revoked')
			expect(label(undefined)).toBe('')
			expect(computed.statusLabel.call({ t, application: null })).toBe('')
		})

		it('does not treat an inherited property name as a status', () => {
			expect(
				computed.statusLabel.call({ t, application: { status: 'toString' } }),
			).toBe('toString')
		})

		it('allows delete only once the application has a status', () => {
			expect(computed.canDelete.call({ application: { status: 'active' } })).toBe(true)
			expect(computed.canDelete.call({ application: {} })).toBe(false)
			expect(computed.canDelete.call({ application: null })).toBe(false)
		})

		it('prefers the id prop over the route param', () => {
			expect(
				computed.routeId.call({ id: 'prop', $route: { params: { id: 'route' } } }),
			).toBe('prop')
			expect(
				computed.routeId.call({ id: '', $route: { params: { id: 'route' } } }),
			).toBe('route')
			expect(computed.routeId.call({ id: '' })).toBe('')
		})
	})

	describe('routeId watcher', () => {
		it('loads a non-empty id and ignores an empty one', () => {
			const vm = { load: vi.fn() }

			watch.routeId.handler.call(vm, 'app-9')
			watch.routeId.handler.call(vm, '')

			expect(watch.routeId.immediate).toBe(true)
			expect(vm.load).toHaveBeenCalledTimes(1)
			expect(vm.load).toHaveBeenCalledWith('app-9')
		})
	})

	describe('load', () => {
		it('fetches the application and its certificate', async () => {
			const vm = makeVm()

			await vm.load('app-1')

			expect(vm.store.fetchApplication).toHaveBeenCalledWith('app-1')
			expect(vm.store.fetchCertificate).toHaveBeenCalledWith('app-1')
			expect(vm.certificate).toBe('PEM')
			expect(vm.error).toBe('')
			expect(vm.loading).toBe(false)
		})

		it('shows the server message when fetching fails', async () => {
			const vm = makeVm()
			vm.store.fetchApplication.mockRejectedValue({
				response: { data: { message: 'Not found' } },
				message: 'Request failed',
			})

			await vm.load('nope')

			expect(vm.error).toBe('Not found')
			expect(vm.store.fetchCertificate).not.toHaveBeenCalled()
			expect(vm.loading).toBe(false)
		})

		it('falls back to the error message, then to a generic one', async () => {
			const vm = makeVm()
			vm.store.fetchApplication.mockRejectedValueOnce(new Error('boom'))
			await vm.load('a')
			expect(vm.error).toBe('boom')

			vm.store.fetchApplication.mockRejectedValueOnce({})
			await vm.load('a')
			expect(vm.error).toBe('Unknown error')
		})
	})

	describe('loadCertificate', () => {
		it('clears the certificate when the suite cannot be fetched', async () => {
			const vm = makeVm({ certificate: 'OLD' })
			vm.store.fetchCertificate.mockRejectedValue(new Error('404'))

			await vm.loadCertificate('app-1')

			expect(vm.certificate).toBe('')
			expect(vm.suiteLoading).toBe(false)
		})
	})

	describe('formatDate', () => {
		it('returns an empty string for a missing date and the locale string otherwise', () => {
			const iso = '2026-05-06T07:08:09Z'

			expect(methods.formatDate('')).toBe('')
			expect(methods.formatDate(null)).toBe('')
			expect(methods.formatDate(iso)).toBe(new Date(iso).toLocaleString())
		})

		it('returns the input when the date cannot be formatted', () => {
			vi.spyOn(Date.prototype, 'toLocaleString').mockImplementation(() => {
				throw new RangeError('bad')
			})

			expect(methods.formatDate('2026-01-01')).toBe('2026-01-01')
		})
	})

	describe('navigation and dialogs', () => {
		it('goes back to the application register', () => {
			const vm = makeVm()

			vm.goBack()

			expect(vm.$router.push).toHaveBeenCalledWith({ name: 'ApplicationRegister' })
		})

		it('does nothing on back without a router', () => {
			const vm = makeVm({ $router: undefined })

			expect(() => vm.goBack()).not.toThrow()
		})

		it('opens and closes the write dialog, and asks before delete', () => {
			const vm = makeVm()

			vm.openWriteDialog()
			expect(vm.writeDialogOpen).toBe(true)
			vm.closeWriteDialog()
			expect(vm.writeDialogOpen).toBe(false)
			vm.confirmDelete()
			expect(vm.deleteDialogOpen).toBe(true)
		})

		it('closes the write dialog and refreshes the secrets panel after a write', () => {
			const refresh = vi.fn()
			const vm = makeVm({
				writeDialogOpen: true,
				$el: { querySelector: vi.fn(() => ({ refresh })) },
				$nextTick: (fn) => fn(),
			})

			vm.onSecretWritten()

			expect(vm.writeDialogOpen).toBe(false)
			expect(vm.$el.querySelector).toHaveBeenCalledWith(
				'[data-testid="application-secrets-panel"]',
			)
			expect(refresh).toHaveBeenCalledTimes(1)
		})

		it('tolerates a missing secrets panel after a write', () => {
			const vm = makeVm({
				$el: { querySelector: () => null },
				$nextTick: (fn) => fn(),
			})

			expect(() => vm.onSecretWritten()).not.toThrow()
		})
	})

	describe('performDelete', () => {
		it('deletes the current application and returns to the register', async () => {
			const vm = makeVm({ deleteDialogOpen: true })

			await vm.performDelete()

			expect(vm.deleteDialogOpen).toBe(false)
			expect(vm.store.deleteApplication).toHaveBeenCalledWith('app-1')
			expect(vm.$router.push).toHaveBeenCalledWith({ name: 'ApplicationRegister' })
		})

		it('stays on the page and shows the error when delete fails', async () => {
			const vm = makeVm()
			vm.store.deleteApplication.mockRejectedValue({
				response: { data: { message: 'Forbidden' } },
			})

			await vm.performDelete()

			expect(vm.error).toBe('Forbidden')
			expect(vm.$router.push).not.toHaveBeenCalled()
		})

		it('uses a generic message when the failure carries none', async () => {
			const vm = makeVm()
			vm.store.deleteApplication.mockRejectedValue({})

			await vm.performDelete()

			expect(vm.error).toBe('Delete failed')
		})
	})
})
