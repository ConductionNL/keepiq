/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin force-revoke result card: no Reinstate after a compromise revoke
 * (keepiq#865), the second suite a compromise revoke also revoked
 * (keepiq#877), a compromise response that did not complete (keepiq#863), and
 * the typed suite-id confirmation (keepiq#871).
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-revoked-as-compromised-cannot-be-reinstated
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSuiteSection from '../../src/components/settings/AdminSuiteSection.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

/**
 * Mount the section, fill the form and run a force-revoke with the given outcome.
 *
 * @param {boolean} markCompromised Whether the administrator ticked compromise.
 * @param {object} outcome What the store's forceRevokeSuite resolves to.
 * @return {Promise<object>} The wrapper.
 */
async function revokeWith(markCompromised, outcome) {
	const store = useEncryptionSuiteStore()
	store.forceRevokeSuite = vi.fn().mockResolvedValue(outcome)
	const wrapper = mount(AdminSuiteSection)
	await wrapper.setData({
		suiteId: 'suite-1',
		confirmSuiteId: 'suite-1',
		reason: 'taken over',
		markCompromised,
	})
	await wrapper.vm.onForceRevoke()
	await wrapper.vm.$nextTick()
	return wrapper
}

const revokedSuite = {
	id: 'suite-1',
	ownerId: 'alice',
	ownerType: 'user',
	status: 'revoked',
}

describe('AdminSuiteSection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('keeps Force-revoke disabled until the typed suite id matches (keepiq#871)', async () => {
		const wrapper = mount(AdminSuiteSection)
		const button = () => wrapper.find('[data-testid="admin-suite-force-revoke"]')

		await wrapper.setData({ suiteId: 'suite-1', reason: 'taken over' })
		expect(button().attributes('disabled')).toBeDefined()

		await wrapper.setData({ confirmSuiteId: 'suite-2' })
		expect(button().attributes('disabled')).toBeDefined()

		await wrapper.setData({ confirmSuiteId: 'suite-1' })
		expect(button().attributes('disabled')).toBeUndefined()
	})

	it('sends the typed suite id with the force-revoke (keepiq#871)', async () => {
		await revokeWith(false, { suite: revokedSuite })

		expect(useEncryptionSuiteStore().forceRevokeSuite).toHaveBeenCalledWith(
			expect.objectContaining({ id: 'suite-1', confirmSuiteId: 'suite-1' }),
		)
	})

	it('offers Reinstate after an ordinary force-revoke', async () => {
		const wrapper = await revokeWith(false, {
			suite: revokedSuite,
			emergencyContactsDestroyed: 0,
			warning:
				'The revoked user may still know these secrets; consider rotating them.',
		})

		expect(wrapper.find('[data-testid="admin-suite-reinstate"]').exists()).toBe(
			true,
		)
	})

	it('shows no Reinstate button after a compromise force-revoke', async () => {
		const wrapper = await revokeWith(true, {
			suite: revokedSuite,
			emergencyContactsDestroyed: 0,
			warning: null,
			cascadeIncomplete: false,
		})

		expect(wrapper.find('[data-testid="admin-suite-reinstate"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="admin-suite-no-reinstate"]').exists(),
		).toBe(true)
	})

	it('shows the second suite, the ended migration and its emergency count', async () => {
		const wrapper = await revokeWith(true, {
			suite: revokedSuite,
			emergencyContactsDestroyed: 1,
			warning: null,
			alsoRevokedSuite: 'suite-2',
			terminatedMigration: 'migration-1',
			alsoRevokedEmergencyContactsDestroyed: 2,
			cascadeIncomplete: false,
		})

		// The global t() stub returns keys verbatim, so the bound values are
		// asserted on the instance, and the plural form on the rendered text.
		const note = wrapper.find('[data-testid="admin-suite-also-revoked"]')
		expect(note.exists()).toBe(true)
		expect(wrapper.vm.alsoRevokedSuite).toBe('suite-2')
		expect(wrapper.vm.terminatedMigration).toBe('migration-1')
		expect(wrapper.vm.alsoRevokedEmergencyContactsDestroyed).toBe(2)
		expect(note.text()).toContain(
			'Revoking the second suite deleted %n emergency-access contacts.',
		)
	})

	it('says so when the compromise response did not complete', async () => {
		const wrapper = await revokeWith(true, {
			suite: revokedSuite,
			emergencyContactsDestroyed: 0,
			warning: null,
			cascadeIncomplete: true,
			cascadeFailed: 3,
		})

		expect(
			wrapper.find('[data-testid="admin-suite-cascade-incomplete"]').exists(),
		).toBe(true)
		expect(wrapper.vm.cascadeFailed).toBe(3)
	})

	it('shows no migration note when nothing else was revoked', async () => {
		const wrapper = await revokeWith(true, {
			suite: revokedSuite,
			emergencyContactsDestroyed: 0,
			warning: null,
			cascadeIncomplete: false,
		})

		expect(
			wrapper.find('[data-testid="admin-suite-also-revoked"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="admin-suite-cascade-incomplete"]').exists(),
		).toBe(false)
	})
})
