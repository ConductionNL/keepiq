/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the Re-establish prompt in `src/views/EmergencyAccessView.vue`.
 *
 * No contact a key rotation invalidated is nudged back in from this view
 * (#804 review). Only the recovery form knows which contacts the owner ticked,
 * so its completion screen is the one place that prompts re-establishing an
 * unreachable contact. Here, every `grantor_rotation*` reason gets a neutral
 * label and no Re-establish, and a break-glass in flight
 * (`grantor_rotation_in_flight`) also gets a warning, because that is what a
 * planted contact looks like. An envelope invalidated because the GRANTEE
 * revoked their own suite (`grantee_revocation`) keeps Re-establish.
 *
 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmergencyAccessView from '../../src/views/EmergencyAccessView.vue'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'

/**
 * Mount the view with the Nextcloud component surface stubbed, a store whose
 * network calls are inert, and the given contacts.
 *
 * @param {Array<object>} contacts The grantor's contacts.
 * @param {Array<object>} [incoming] The contacts where the user is the grantee.
 * @return {Promise<{wrapper: object, store: object}>} The wrapper and store.
 */
async function mountView(contacts, incoming = []) {
	const store = useEmergencyAccessStore()
	vi.spyOn(store, 'fetchContacts').mockResolvedValue()
	vi.spyOn(store, 'fetchIncoming').mockResolvedValue()
	store.contacts = contacts
	store.incoming = incoming

	const wrapper = mount(EmergencyAccessView, {
		global: {
			mixins: [
				{
					methods: {
						t: (app, text, vars) =>
							vars
								? Object.keys(vars).reduce(
										(out, k) =>
											out.replace(`{${k}}`, String(vars[k])),
										text,
									)
								: text,
						n: (app, s, p, count) =>
							(count === 1 ? s : p).replace('%n', String(count)),
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
				// Render the dialog's default + actions slots so the gate is visible.
				NcDialog: {
					props: ['open'],
					template:
						'<div v-if="open" class="nc-dialog"><slot /><slot name="actions" /></div>',
				},
			},
		},
	})
	await wrapper.vm.$nextTick()
	return { wrapper, store }
}

/**
 * An invalidated contact with the given reason.
 *
 * @param {string|null} invalidatedReason The server's reason.
 * @return {object} The contact.
 */
function invalidated(invalidatedReason) {
	return {
		id: 'rel-1',
		granteeUserId: 'bob',
		state: 'invalidated',
		invalidatedReason,
		waitPeriodDays: 7,
	}
}

describe('EmergencyAccessView — re-establish prompt', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it.each([['grantee_revocation'], [null]])(
		'offers Re-establish for a contact not invalidated by a rotation (%s)',
		async (reason) => {
			const { wrapper } = await mountView([invalidated(reason)])
			expect(
				wrapper.find('[data-testid="emergency-reestablish"]').exists(),
			).toBe(true)
			expect(
				wrapper.find('[data-testid="emergency-in-flight-warning"]').exists(),
			).toBe(false)
		},
	)

	// grantor_rotation_not_carried: rows written by an earlier revision of #804.
	it.each([['grantor_rotation'], ['grantor_rotation_not_carried']])(
		'offers nothing for a contact a rotation did not carry (%s)',
		async (reason) => {
			const { wrapper } = await mountView([invalidated(reason)])
			expect(
				wrapper.find('[data-testid="emergency-reestablish"]').exists(),
			).toBe(false)
			expect(
				wrapper.find('[data-testid="emergency-in-flight-warning"]').exists(),
			).toBe(false)
			expect(wrapper.find('.emergency-access__state').text()).toBe(
				'Invalidated',
			)
		},
	)

	// #804 review, round 4: without the button the owner must still be told, in
	// the standing view, that a rotation removed the contact.
	it('says, in text only, that a rotation removed the contact', async () => {
		const { wrapper } = await mountView([invalidated('grantor_rotation')])
		const notice = wrapper.find('[data-testid="emergency-rotation-notice"]')
		expect(notice.exists()).toBe(true)
		expect(notice.text()).toContain('key rotation')
		expect(notice.find('button').exists()).toBe(false)
	})

	it('shows no rotation notice for a contact a rotation did not touch', async () => {
		const { wrapper } = await mountView([invalidated('grantee_revocation')])
		expect(
			wrapper.find('[data-testid="emergency-rotation-notice"]').exists(),
		).toBe(false)
	})

	// The grantee's list has no reason and nothing the grantee can re-establish.
	it('labels an invalidated incoming contact plainly for the grantee', async () => {
		const { wrapper } = await mountView(
			[],
			[
				{
					id: 'rel-9',
					grantorUserId: 'alice',
					state: 'invalidated',
					waitPeriodDays: 7,
				},
			],
		)
		const item = wrapper.find('[data-testid="emergency-incoming-item"]')
		expect(item.find('.emergency-access__state').text()).toBe('Invalidated')
	})

	it('warns, and offers nothing, for a contact with a break-glass in flight', async () => {
		const { wrapper } = await mountView([
			invalidated('grantor_rotation_in_flight'),
		])
		expect(wrapper.find('[data-testid="emergency-reestablish"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="emergency-in-flight-warning"]').exists(),
		).toBe(true)
	})

	it('offers nothing for a contact that is not invalidated', async () => {
		const { wrapper } = await mountView([
			{
				id: 'rel-1',
				granteeUserId: 'bob',
				state: 'granted',
				waitPeriodDays: 7,
			},
		])
		expect(wrapper.find('[data-testid="emergency-reestablish"]').exists()).toBe(
			false,
		)
	})
})
