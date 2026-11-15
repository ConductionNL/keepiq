/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/modals/MySendsDialog.vue`: it loads the user's
 * active sends when mounted, lists them with views left, expiry and password
 * state, and revokes the clicked one; store failures do not escape.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MySendsDialog from '../../src/modals/MySendsDialog.vue'
import { useEphemeralSendStore } from '../../src/store/modules/ephemeralSend.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcDialog: {
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
}

describe('MySendsDialog', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useEphemeralSendStore()
		store.fetchSends = vi.fn().mockResolvedValue()
		store.revoke = vi.fn().mockResolvedValue()
	})

	it('loads the sends when mounted and explains an empty list', async () => {
		const wrapper = mount(MySendsDialog, { props: { open: true }, global: { stubs } })
		await flush()

		expect(store.fetchSends).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="my-sends-table"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No active sends.')
	})

	it('lists each send with views left, expiry and password state', async () => {
		store.sends = [
			{ id: 's1', payloadType: 'text', remainingViews: 2, expiresAt: '2026-12-01T10:00:00Z', hasPassword: true },
			{ id: 's2', payloadType: 'credential', remainingViews: 1, expiresAt: null, hasPassword: false },
		]
		const wrapper = mount(MySendsDialog, { props: { open: true }, global: { stubs } })
		await flush()

		const first = wrapper.findAll('[data-testid="send-row-s1"] td').map((td) => td.text())
		expect(first.slice(0, 4)).toEqual([
			'text',
			'2',
			new Date('2026-12-01T10:00:00Z').toLocaleString(),
			'yes',
		])
		const second = wrapper.findAll('[data-testid="send-row-s2"] td').map((td) => td.text())
		expect(second.slice(0, 4)).toEqual(['credential', '1', 'never', 'no'])
	})

	it('revokes the clicked send', async () => {
		store.sends = [{ id: 's9', payloadType: 'text', remainingViews: 1, expiresAt: null, hasPassword: false }]
		const wrapper = mount(MySendsDialog, { props: { open: true }, global: { stubs } })
		await flush()

		await wrapper.find('[data-testid="send-revoke-s9"]').trigger('click')

		expect(store.revoke).toHaveBeenCalledWith('s9')
	})

	it('swallows store failures, which the store surfaces itself', async () => {
		store.fetchSends = vi.fn().mockRejectedValue(new Error('offline'))
		store.revoke = vi.fn().mockRejectedValue(new Error('gone'))
		const wrapper = mount(MySendsDialog, { props: { open: true }, global: { stubs } })
		await flush()

		await expect(wrapper.vm.onRevoke('s1')).resolves.toBeUndefined()
		expect(store.revoke).toHaveBeenCalledWith('s1')
	})

	it('emits close from the Close button and from the dialog', async () => {
		const wrapper = mount(MySendsDialog, { props: { open: true }, global: { stubs } })
		await flush()

		await wrapper.find('button').trigger('click')
		wrapper.findComponent(stubs.NcDialog).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
