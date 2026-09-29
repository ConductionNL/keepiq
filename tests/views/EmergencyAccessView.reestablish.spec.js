/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the Re-establish prompt in `src/views/EmergencyAccessView.vue`.
 *
 * A compromise recovery leaves three kinds of invalidated contact behind, and
 * only one of them may be nudged back in (#804 review):
 *  - unreachable (reason `grantor_rotation`, or any older reason): Re-establish;
 *  - not carried by the owner's choice (`grantor_rotation_not_carried`):
 *    neutral, no call to action;
 *  - break-glass in flight (`grantor_rotation_in_flight`): a warning, no
 *    call to action, because that is what a planted contact looks like.
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
 * @return {Promise<{wrapper: object, store: object}>} The wrapper and store.
 */
async function mountView(contacts) {
	const store = useEmergencyAccessStore()
	vi.spyOn(store, 'fetchContacts').mockResolvedValue()
	vi.spyOn(store, 'fetchIncoming').mockResolvedValue()
	store.contacts = contacts
	store.incoming = []

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

	it.each([['grantor_rotation'], ['grantee_revocation'], [null]])(
		'offers Re-establish for an unreachable contact (%s)',
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

	it('offers nothing for a contact the owner chose not to carry', async () => {
		const { wrapper } = await mountView([
			invalidated('grantor_rotation_not_carried'),
		])
		expect(wrapper.find('[data-testid="emergency-reestablish"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="emergency-in-flight-warning"]').exists(),
		).toBe(false)
		expect(wrapper.find('.emergency-access__state').text()).toBe('Invalidated')
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
