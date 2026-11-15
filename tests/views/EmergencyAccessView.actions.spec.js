/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The actions of `src/views/EmergencyAccessView.vue` as a user drives them:
 * designating a contact from the form (trimmed id, chosen wait period, master
 * password; the form clears after, and a refusal is shown), declining a
 * request as the grantor, and requesting and recovering a vault as the
 * grantee, with the recovered notice or the reason it failed.
 *
 * @spec openspec/specs/emergency-access/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import EmergencyAccessView from '../../src/views/EmergencyAccessView.vue'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'

function field (name) {
  return {
	name,
	props: ['modelValue', 'options', 'reduce'],
	emits: ['update:modelValue'],
	render() {
		return h('div', { 'data-field': name })
	},
}
}

function t (app, text, vars) {
  return vars
		? Object.keys(vars).reduce(
				(out, k) => out.replace(`{${k}}`, String(vars[k])),
				text,
			)
		: text
}

/**
 * Mount the view over a store whose network calls are spies.
 *
 * @param {Array<object>} contacts The grantor's contacts.
 * @param {Array<object>} incoming The relationships where the user is the grantee.
 * @return {Promise<{wrapper: object, store: object}>} The wrapper and store.
 */
async function mountView(contacts = [], incoming = []) {
	const store = useEmergencyAccessStore()
	vi.spyOn(store, 'fetchContacts').mockResolvedValue()
	vi.spyOn(store, 'fetchIncoming').mockResolvedValue()
	store.contacts = contacts
	store.incoming = incoming

	const wrapper = mount(EmergencyAccessView, {
		global: {
			mixins: [{ methods: { t } }],
			stubs: {
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
				NcTextField: field('NcTextField'),
				NcPasswordField: field('NcPasswordField'),
				NcSelect: field('NcSelect'),
				NcEmptyContent: { template: '<div />' },
				EmergencyRevokeDialog: { template: '<div />' },
			},
		},
	})
	await flushPromises()
	return { wrapper, store }
}

const byTestId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)
function fieldOf (wrapper, id) {
  return wrapper.findAllComponents(`[data-testid="${id}"]`)[0]
}

describe('EmergencyAccessView actions', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		globalThis.t = t
	})

	it('designates the contact typed in, with the chosen wait, then clears the form', async () => {
		const { wrapper, store } = await mountView()
		const designate = vi.spyOn(store, 'designate').mockResolvedValue()
		const submit = byTestId(wrapper, 'emergency-designate-submit')
		expect(submit.attributes('disabled')).toBeDefined()

		const wait = fieldOf(wrapper, 'emergency-wait-select')
		expect(wait.props('options').map((o) => o.value)).toEqual([1, 3, 7, 30])
		expect(wait.props('reduce')({ value: 30, label: '30 days' })).toBe(30)

		await fieldOf(wrapper, 'emergency-grantee-input').vm.$emit(
			'update:modelValue',
			'  anna  ',
		)
		await wait.vm.$emit('update:modelValue', '30')
		await fieldOf(wrapper, 'emergency-master-input').vm.$emit(
			'update:modelValue',
			'hunter2',
		)
		expect(submit.attributes('disabled')).toBeUndefined()

		await submit.trigger('click')
		await flushPromises()
		expect(designate).toHaveBeenCalledWith({
			granteeUserId: 'anna',
			waitPeriodDays: 30,
			masterPassword: 'hunter2',
		})
		expect(fieldOf(wrapper, 'emergency-grantee-input').props('modelValue')).toBe(
			'',
		)
		expect(fieldOf(wrapper, 'emergency-master-input').props('modelValue')).toBe(
			'',
		)
		expect(byTestId(wrapper, 'emergency-error').exists()).toBe(false)
	})

	it('shows why a designation was refused and keeps what was typed', async () => {
		const { wrapper, store } = await mountView()
		vi.spyOn(store, 'designate')
			.mockRejectedValueOnce({
				response: { data: { message: 'Contact has no certificate' } },
			})
			.mockRejectedValueOnce(new Error('Wrong master password'))
			.mockRejectedValueOnce({})
		await fieldOf(wrapper, 'emergency-grantee-input').vm.$emit(
			'update:modelValue',
			'bob',
		)
		await fieldOf(wrapper, 'emergency-master-input').vm.$emit(
			'update:modelValue',
			'pw',
		)

		const messages = []
		for (let i = 0; i < 3; i++) {
			await byTestId(wrapper, 'emergency-designate-submit').trigger('click')
			await flushPromises()
			messages.push(byTestId(wrapper, 'emergency-error').text())
		}
		expect(messages).toEqual([
			'Contact has no certificate',
			'Wrong master password',
			'Could not designate the contact',
		])
		expect(fieldOf(wrapper, 'emergency-grantee-input').props('modelValue')).toBe(
			'bob',
		)
	})

	it('declines a pending request as the grantor', async () => {
		const { wrapper, store } = await mountView([
			{
				id: 'c-1',
				granteeUserId: 'anna',
				state: 'requested',
				waitPeriodDays: 7,
			},
		])
		const decline = vi.spyOn(store, 'decline').mockResolvedValue()
		expect(wrapper.find('.emergency-access__state').text()).toBe('Requested')

		await byTestId(wrapper, 'emergency-decline').trigger('click')
		expect(decline).toHaveBeenCalledWith('c-1')
	})

	it('re-establishes a contact the grantee invalidated by filling the form with them', async () => {
		const { wrapper } = await mountView([
			{ id: 'c-2', granteeUserId: 'carla', state: 'invalidated', invalidatedReason: 'grantee_revocation', waitPeriodDays: 3 },
		])
		expect(wrapper.find('.emergency-access__state').text()).toBe('Invalidated (re-establish)')

		await byTestId(wrapper, 'emergency-reestablish').trigger('click')
		expect(fieldOf(wrapper, 'emergency-grantee-input').props('modelValue')).toBe('carla')
	})

	it('requests access to a granted vault and recovers an approved one', async () => {
		const { wrapper, store } = await mountView(
			[],
			[
				{ id: 'g-1', grantorUserId: 'oma', state: 'granted' },
				{ id: 'g-2', grantorUserId: 'opa', state: 'approved' },
				{ id: 'g-3', grantorUserId: 'tante', state: 'invalidated' },
			],
		)
		const request = vi.spyOn(store, 'request').mockResolvedValue()
		const recover = vi.spyOn(store, 'recover').mockResolvedValue()
		expect(
			wrapper
				.findAll(
					'[data-testid="emergency-incoming-item"] .emergency-access__state',
				)
				.map((s) => s.text()),
		).toEqual(['Granted', 'Approved', 'Invalidated'])

		await byTestId(wrapper, 'emergency-request').trigger('click')
		expect(request).toHaveBeenCalledWith('g-1')
		expect(byTestId(wrapper, 'emergency-recovered').exists()).toBe(false)

		await byTestId(wrapper, 'emergency-recover').trigger('click')
		await flushPromises()
		expect(recover).toHaveBeenCalledWith('g-2')
		expect(byTestId(wrapper, 'emergency-recovered').exists()).toBe(true)
	})

	it('says why a recovery failed', async () => {
		const { wrapper, store } = await mountView(
			[],
			[{ id: 'g-2', grantorUserId: 'opa', state: 'approved' }],
		)
		vi.spyOn(store, 'recover')
			.mockRejectedValueOnce({
				response: { data: { message: 'Envelope invalidated' } },
			})
			.mockRejectedValueOnce({})

		await byTestId(wrapper, 'emergency-recover').trigger('click')
		await flushPromises()
		expect(byTestId(wrapper, 'emergency-error').text()).toBe(
			'Envelope invalidated',
		)

		await byTestId(wrapper, 'emergency-recover').trigger('click')
		await flushPromises()
		expect(byTestId(wrapper, 'emergency-error').text()).toBe(
			'Could not recover access',
		)
		expect(byTestId(wrapper, 'emergency-recovered').exists()).toBe(false)
	})
})
