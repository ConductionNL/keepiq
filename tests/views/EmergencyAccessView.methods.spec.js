/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the contact-state and action methods of
 * `src/views/EmergencyAccessView.vue`: the state labels (grantor and grantee
 * side), the rotation-removed notice and the Re-establish gate, the designate
 * form (payload, reset, error surfacing), the grantee request / recover paths
 * and the grantor veto. The store's network actions are stubbed; the
 * component logic runs for real.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmergencyAccessView from '../../src/views/EmergencyAccessView.vue'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'

/**
 * Mount the view with the Nextcloud component surface stubbed and the
 * store's load calls inert.
 *
 * @return {Promise<{wrapper: object, store: object}>} The wrapper and store.
 */
async function mountView() {
	const store = useEmergencyAccessStore()
	vi.spyOn(store, 'fetchContacts').mockResolvedValue()
	vi.spyOn(store, 'fetchIncoming').mockResolvedValue()
	store.contacts = []
	store.incoming = []

	const wrapper = mount(EmergencyAccessView, {
		global: {
			mixins: [
				{
					methods: {
						t: (app, text) => text,
						n: (app, s, p, count) => (count === 1 ? s : p),
					},
				},
			],
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcPasswordField: { template: '<input />' },
				NcTextField: { template: '<input />' },
				NcSelect: { template: '<div />' },
				NcEmptyContent: { template: '<div />' },
				NcDialog: { template: '<div />' },
				EmergencyRevokeDialog: { template: '<div />' },
			},
		},
	})
	await wrapper.vm.$nextTick()
	return { wrapper, store }
}

describe('EmergencyAccessView — methods', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('loads contacts and incoming relationships on mount', async () => {
		const { store } = await mountView()
		expect(store.fetchContacts).toHaveBeenCalledTimes(1)
		expect(store.fetchIncoming).toHaveBeenCalledTimes(1)
	})

	describe('waitOptions and canDesignate', () => {
		it('offers the four wait periods in days', async () => {
			const { wrapper } = await mountView()
			expect(wrapper.vm.waitOptions.map((o) => o.value)).toEqual([1, 3, 7, 30])
			expect(wrapper.vm.waitOptions[0].label).toBe('1 day')
			expect(wrapper.vm.waitOptions[3].label).toBe('30 days')
		})

		it('needs a non-blank grantee and a master password', async () => {
			const { wrapper } = await mountView()
			expect(wrapper.vm.canDesignate).toBe(false)
			wrapper.vm.granteeUserId = '   '
			wrapper.vm.masterPassword = 'pw'
			expect(wrapper.vm.canDesignate).toBe(false)
			wrapper.vm.granteeUserId = 'bob'
			expect(wrapper.vm.canDesignate).toBe(true)
			wrapper.vm.masterPassword = ''
			expect(wrapper.vm.canDesignate).toBe(false)
		})
	})

	describe('stateLabel', () => {
		it('maps each known state to its label', async () => {
			const { wrapper } = await mountView()
			const label = (state) => wrapper.vm.stateLabel({ state })
			expect(label('granted')).toBe('Granted')
			expect(label('requested')).toBe('Requested')
			expect(label('approved')).toBe('Approved')
			expect(label('declined')).toBe('Declined')
		})

		it('falls back to the raw state for an unknown one', async () => {
			const { wrapper } = await mountView()
			expect(wrapper.vm.stateLabel({ state: 'mystery' })).toBe('mystery')
		})

		it('invites re-establishing an invalidated contact not removed by rotation', async () => {
			const { wrapper } = await mountView()
			expect(
				wrapper.vm.stateLabel({
					state: 'invalidated',
					invalidatedReason: 'grantee_suite_revoked',
				}),
			).toBe('Invalidated (re-establish)')
		})

		it('labels a rotation-invalidated contact plainly', async () => {
			const { wrapper } = await mountView()
			expect(
				wrapper.vm.stateLabel({
					state: 'invalidated',
					invalidatedReason: 'grantor_rotation',
				}),
			).toBe('Invalidated')
		})
	})

	describe('incomingStateLabel', () => {
		it('never offers re-establish to the grantee', async () => {
			const { wrapper } = await mountView()
			expect(
				wrapper.vm.incomingStateLabel({
					state: 'invalidated',
					invalidatedReason: 'grantee_suite_revoked',
				}),
			).toBe('Invalidated')
		})

		it('delegates other states to stateLabel', async () => {
			const { wrapper } = await mountView()
			expect(wrapper.vm.incomingStateLabel({ state: 'approved' })).toBe('Approved')
		})
	})

	describe('rotationRemoved and canReestablish', () => {
		it('flags a finished rotation removal', async () => {
			const { wrapper } = await mountView()
			const contact = { state: 'invalidated', invalidatedReason: 'grantor_rotation' }
			expect(wrapper.vm.rotationRemoved(contact)).toBe(true)
			expect(wrapper.vm.canReestablish(contact)).toBe(false)
		})

		it('does not flag a rotation still in flight', async () => {
			const { wrapper } = await mountView()
			const contact = {
				state: 'invalidated',
				invalidatedReason: 'grantor_rotation_in_flight',
			}
			expect(wrapper.vm.rotationRemoved(contact)).toBe(false)
			expect(wrapper.vm.canReestablish(contact)).toBe(false)
		})

		it('treats a missing reason as not rotation-caused', async () => {
			const { wrapper } = await mountView()
			const contact = { state: 'invalidated', invalidatedReason: null }
			expect(wrapper.vm.rotationRemoved(contact)).toBe(false)
			expect(wrapper.vm.canReestablish(contact)).toBe(true)
		})

		it('never applies to a contact that is not invalidated', async () => {
			const { wrapper } = await mountView()
			const contact = { state: 'granted', invalidatedReason: 'grantor_rotation' }
			expect(wrapper.vm.rotationRemoved(contact)).toBe(false)
			expect(wrapper.vm.canReestablish(contact)).toBe(false)
		})
	})

	describe('designate', () => {
		it('sends the trimmed grantee, numeric wait period and password, then clears the form', async () => {
			const { wrapper, store } = await mountView()
			const designate = vi.spyOn(store, 'designate').mockResolvedValue()
			wrapper.vm.granteeUserId = '  bob  '
			wrapper.vm.waitPeriod = '30'
			wrapper.vm.masterPassword = 'secret-pw'

			await wrapper.vm.designate()

			expect(designate).toHaveBeenCalledWith({
				granteeUserId: 'bob',
				waitPeriodDays: 30,
				masterPassword: 'secret-pw',
			})
			expect(wrapper.vm.granteeUserId).toBe('')
			expect(wrapper.vm.masterPassword).toBe('')
			expect(wrapper.vm.error).toBe('')
			expect(wrapper.vm.busy).toBe(false)
		})

		it('surfaces the server message and keeps the form', async () => {
			const { wrapper, store } = await mountView()
			vi.spyOn(store, 'designate').mockRejectedValue({
				response: { data: { message: 'Unknown user' } },
			})
			wrapper.vm.granteeUserId = 'ghost'
			wrapper.vm.masterPassword = 'pw'

			await wrapper.vm.designate()

			expect(wrapper.vm.error).toBe('Unknown user')
			expect(wrapper.vm.granteeUserId).toBe('ghost')
			expect(wrapper.vm.masterPassword).toBe('pw')
			expect(wrapper.vm.busy).toBe(false)
		})

		it('falls back to the error message, then to a generic one', async () => {
			const { wrapper, store } = await mountView()
			const designate = vi.spyOn(store, 'designate')
			wrapper.vm.granteeUserId = 'bob'

			designate.mockRejectedValueOnce(new Error('Wrong password'))
			await wrapper.vm.designate()
			expect(wrapper.vm.error).toBe('Wrong password')

			designate.mockRejectedValueOnce({})
			await wrapper.vm.designate()
			expect(wrapper.vm.error).toBe('Could not designate the contact')
		})
	})

	describe('confirmRevoke error fallbacks', () => {
		it('uses the error message, then the generic hint', async () => {
			const { wrapper, store } = await mountView()
			const revoke = vi.spyOn(store, 'revoke')
			wrapper.vm.promptRevoke('rel-1')
			wrapper.vm.revokePassword = 'pw'

			revoke.mockRejectedValueOnce(new Error('Proof rejected'))
			await wrapper.vm.confirmRevoke()
			expect(revoke).toHaveBeenLastCalledWith('rel-1', 'pw')
			expect(wrapper.vm.revokeError).toBe('Proof rejected')
			expect(wrapper.vm.revokeTarget).toBe('rel-1')

			revoke.mockRejectedValueOnce(null)
			await wrapper.vm.confirmRevoke()
			expect(wrapper.vm.revokeError).toBe(
				'Could not revoke. Check your master password.',
			)
			expect(wrapper.vm.revoking).toBe(false)
		})
	})

	describe('grantee and veto actions', () => {
		it('decline vetoes the given relationship', async () => {
			const { wrapper, store } = await mountView()
			const decline = vi.spyOn(store, 'decline').mockResolvedValue()
			await wrapper.vm.decline('rel-7')
			expect(decline).toHaveBeenCalledWith('rel-7')
		})

		it('request starts break-glass for the given relationship', async () => {
			const { wrapper, store } = await mountView()
			const request = vi.spyOn(store, 'request').mockResolvedValue()
			await wrapper.vm.request('rel-8')
			expect(request).toHaveBeenCalledWith('rel-8')
		})

		it('recover marks the view recovered on success', async () => {
			const { wrapper, store } = await mountView()
			const recover = vi.spyOn(store, 'recover').mockResolvedValue()
			wrapper.vm.error = 'stale'
			await wrapper.vm.recover('rel-9')
			expect(recover).toHaveBeenCalledWith('rel-9')
			expect(wrapper.vm.recovered).toBe(true)
			expect(wrapper.vm.error).toBe('')
		})

		it('recover surfaces each error shape and stays unrecovered', async () => {
			const { wrapper, store } = await mountView()
			const recover = vi.spyOn(store, 'recover')

			recover.mockRejectedValueOnce({ response: { data: { message: 'Not approved yet' } } })
			await wrapper.vm.recover('rel-9')
			expect(wrapper.vm.error).toBe('Not approved yet')

			recover.mockRejectedValueOnce(new Error('Decrypt failed'))
			await wrapper.vm.recover('rel-9')
			expect(wrapper.vm.error).toBe('Decrypt failed')

			recover.mockRejectedValueOnce(undefined)
			await wrapper.vm.recover('rel-9')
			expect(wrapper.vm.error).toBe('Could not recover access')
			expect(wrapper.vm.recovered).toBe(false)
		})

		it('scrollToDesignate pre-fills the grantee', async () => {
			const { wrapper } = await mountView()
			wrapper.vm.scrollToDesignate('carol')
			expect(wrapper.vm.granteeUserId).toBe('carol')
		})
	})
})
