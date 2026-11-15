/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin suite section's account-recovery warning lookup, its error
 * messages for a refused force-revoke, and reinstating a revoked suite.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-force-revocation-warns-about-enrolled-users
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSuiteSection from '../../src/components/settings/AdminSuiteSection.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

const ENROLLED = '/apps/keepiq/api/v1/recovery/admin/enrolled'

describe('AdminSuiteSection recovery warning', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('does not look up ids shorter than eight characters', async () => {
		const get = vi.spyOn(axios, 'get')
		const wrapper = mount(AdminSuiteSection)

		await wrapper.setData({ suiteId: ' abc1234 ' })
		await flushPromises()

		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="admin-suite-recovery-warning"]').exists()).toBe(false)
	})

	it('warns when the trimmed suite id belongs to an enrolled user', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { enrolled: true } })
		const wrapper = mount(AdminSuiteSection)

		await wrapper.setData({ suiteId: '  suite-0001 ' })
		await flushPromises()

		expect(get).toHaveBeenCalledWith(ENROLLED, { params: { suiteId: 'suite-0001' } })
		expect(wrapper.find('[data-testid="admin-suite-recovery-warning"]').exists()).toBe(true)
	})

	it('does not warn for a truthy but not-true enrolled flag', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { enrolled: 'yes' } })
		const wrapper = mount(AdminSuiteSection)

		await wrapper.setData({ suiteId: 'suite-0001' })
		await flushPromises()

		expect(wrapper.vm.enrolledInRecovery).toBe(false)
	})

	it('drops the warning when the lookup fails', async () => {
		vi.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: { enrolled: true } })
			.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(AdminSuiteSection)

		await wrapper.setData({ suiteId: 'suite-0001' })
		await flushPromises()
		expect(wrapper.vm.enrolledInRecovery).toBe(true)

		await wrapper.setData({ suiteId: 'suite-0002' })
		await flushPromises()
		expect(wrapper.vm.enrolledInRecovery).toBe(false)
	})

	it('ignores an answer for an id the admin has since changed', async () => {
		let answer
		vi.spyOn(axios, 'get')
			.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve }))
			.mockResolvedValueOnce({ data: { enrolled: false } })
		const wrapper = mount(AdminSuiteSection)

		await wrapper.setData({ suiteId: 'suite-0001' })
		await wrapper.setData({ suiteId: 'suite-0002' })
		await flushPromises()
		answer({ data: { enrolled: true } })
		await flushPromises()

		expect(wrapper.vm.enrolledInRecovery).toBe(false)
	})
})

describe('AdminSuiteSection errors and reinstate', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it.each([
		[{ response: { data: { message: 'Suite ID does not match' } } }, 'Suite ID does not match'],
		[new Error('Password confirmation cancelled'), 'Password confirmation cancelled'],
		[{}, 'Failed to force-revoke suite'],
	])('surfaces a refused force-revoke (%#)', async (failure, message) => {
		useEncryptionSuiteStore().forceRevokeSuite = vi.fn().mockRejectedValue(failure)
		const wrapper = mount(AdminSuiteSection)

		await wrapper.vm.onForceRevoke()
		await flushPromises()

		expect(wrapper.find('[data-testid="admin-suite-error"]').text()).toBe(message)
		expect(wrapper.vm.result).toBeNull()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reinstates the revoked suite and shows the reinstated result', async () => {
		const store = useEncryptionSuiteStore()
		store.forceRevokeSuite = vi.fn().mockResolvedValue({
			suite: { id: 'suite-1', ownerId: 'alice', ownerType: 'user', status: 'revoked' },
			emergencyContactsDestroyed: 0,
		})
		store.reinstateSuiteAdmin = vi.fn().mockResolvedValue({
			id: 'suite-1', ownerId: 'alice', ownerType: 'user', status: 'active',
		})
		const wrapper = mount(AdminSuiteSection)
		await wrapper.vm.onForceRevoke()

		await wrapper.vm.onReinstate()
		await flushPromises()

		expect(store.reinstateSuiteAdmin).toHaveBeenCalledWith('suite-1')
		expect(wrapper.vm.result.status).toBe('active')
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.busy).toBe(false)
	})

	it.each([
		[{ response: { data: { message: 'Compromised suites stay revoked' } } }, 'Compromised suites stay revoked'],
		[new Error('Network Error'), 'Network Error'],
		[{}, 'Failed to reinstate suite'],
	])('keeps the revoked result when reinstating fails (%#)', async (failure, message) => {
		const store = useEncryptionSuiteStore()
		store.forceRevokeSuite = vi.fn().mockResolvedValue({
			suite: { id: 'suite-1', ownerId: 'alice', ownerType: 'user', status: 'revoked' },
		})
		store.reinstateSuiteAdmin = vi.fn().mockRejectedValue(failure)
		const wrapper = mount(AdminSuiteSection)
		await wrapper.vm.onForceRevoke()

		await wrapper.vm.onReinstate()
		await flushPromises()

		expect(wrapper.find('[data-testid="admin-suite-error"]').text()).toBe(message)
		expect(wrapper.vm.result.status).toBe('revoked')
		expect(wrapper.vm.busy).toBe(false)
	})
})
