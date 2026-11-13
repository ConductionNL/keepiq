/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin honey-credential section: it lists every alert with its state
 * (handled, snoozed, open), offers acknowledge and snooze only where they
 * still apply, and shortens long user agents.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HoneySection from '../../src/components/settings/HoneySection.vue'
import { useHoneyStore } from '../../src/store/modules/honey.js'

// The global t() stub returns the key; these assertions need the substituted text.
const tWithVars = {
	methods: {
		t: (_app, key, vars) =>
			Object.entries(vars || {}).reduce(
				(acc, [name, value]) => acc.replace(`{${name}}`, value),
				key,
			),
	},
}

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { template: '<div data-testid="honey-error"><slot /></div>' },
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
}

const FUTURE = new Date(Date.now() + 3600 * 1000).toISOString()
const PAST = new Date(Date.now() - 3600 * 1000).toISOString()

describe('HoneySection', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useHoneyStore()
		store.fetchAlerts = vi.fn().mockResolvedValue([])
		store.acknowledge = vi.fn().mockResolvedValue(true)
		store.snooze = vi.fn().mockResolvedValue(true)
	})

	it('fetches the alerts and explains the empty list', () => {
		const wrapper = mount(HoneySection, { global: { stubs, mixins: [tWithVars] } })

		expect(store.fetchAlerts).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="honey-admin-alerts"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No honey alerts.')
	})

	it('shows the store error', () => {
		store.error = 'Not allowed'
		const wrapper = mount(HoneySection, { global: { stubs, mixins: [tWithVars] } })

		expect(wrapper.find('[data-testid="honey-error"]').text()).toBe('Not allowed')
	})

	it('shows each alert state and only the actions that still apply', () => {
		store.alerts = [
			{ id: 'a', accessorType: 'user', accessorId: 'mallory', channel: 'web', accessCount: 2, acknowledgedAt: null, snoozedUntil: null },
			{ id: 'b', accessorType: 'link', accessorId: null, channel: 'link', accessCount: 1, acknowledgedAt: '2026-01-01', acknowledgedBy: 'admin', snoozedUntil: null },
			{ id: 'c', accessorType: 'app', accessorId: 'ci', channel: 'api', accessCount: 5, acknowledgedAt: null, snoozedUntil: FUTURE },
			{ id: 'd', accessorType: 'app', accessorId: 'cd', channel: 'api', accessCount: 1, acknowledgedAt: null, snoozedUntil: PAST },
		]
		const wrapper = mount(HoneySection, { global: { stubs, mixins: [tWithVars] } })
		const row = (id) => wrapper.find(`[data-testid="honey-admin-alert-${id}"]`)

		expect(row('a').text()).toContain('OPEN')
		expect(row('a').find('[data-testid="honey-admin-ack-a"]').exists()).toBe(true)
		expect(row('a').find('[data-testid="honey-admin-snooze-a"]').exists()).toBe(true)

		expect(row('b').text()).toContain('link:')
		expect(row('b').text()).toContain('anonymous')
		expect(row('b').text()).toContain('handled by admin')
		expect(row('b').find('[data-testid="honey-admin-ack-b"]').exists()).toBe(false)

		expect(row('c').text()).toContain('snoozed')
		expect(row('c').find('[data-testid="honey-admin-snooze-c"]').exists()).toBe(false)
		expect(row('c').find('[data-testid="honey-admin-ack-c"]').exists()).toBe(true)

		expect(row('d').text()).toContain('OPEN')
		expect(row('d').find('[data-testid="honey-admin-snooze-d"]').exists()).toBe(true)
	})

	it('acknowledges and snoozes the clicked alert', async () => {
		store.alerts = [{ id: 'x1', accessorType: 'user', accessorId: 'u', channel: 'web', accessCount: 1 }]
		const wrapper = mount(HoneySection, { global: { stubs, mixins: [tWithVars] } })

		await wrapper.find('[data-testid="honey-admin-ack-x1"]').trigger('click')
		await wrapper.find('[data-testid="honey-admin-snooze-x1"]').trigger('click')

		expect(store.acknowledge).toHaveBeenCalledWith('x1')
		expect(store.snooze).toHaveBeenCalledWith('x1')
	})

	it('shortens user agents over 60 characters and dashes a missing date', () => {
		const wrapper = mount(HoneySection, { global: { stubs, mixins: [tWithVars] } })
		const long = 'a'.repeat(61)

		expect(wrapper.vm.shortAgent(long)).toBe('a'.repeat(60) + '…')
		expect(wrapper.vm.shortAgent('a'.repeat(60))).toBe('a'.repeat(60))
		expect(wrapper.vm.shortAgent(null)).toBe('')
		expect(wrapper.vm.formatDate(null)).toBe('—')
		expect(wrapper.vm.formatDate('2026-03-01T10:00:00Z')).toBe(
			new Date('2026-03-01T10:00:00Z').toLocaleString(),
		)
	})
})
