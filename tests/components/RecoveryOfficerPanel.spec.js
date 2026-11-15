/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recovery officer's panel: it loads the officer view on creation, shows
 * nothing to a non-officer, offers "create the recovery key" until a key
 * exists, and per request shows the phrase to compare, approves only with a
 * typed master password, declines a pending request, and hands the key over
 * once this officer's approval is in. Every action disables the buttons while
 * it runs and surfaces the server's message (else the error's own) on
 * failure. The store's crypto-heavy actions are replaced; this pins what the
 * panel hands them.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecoveryOfficerPanel from '../../src/components/RecoveryOfficerPanel.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const substitutingT = {
	methods: {
		t: (_app, key, vars) =>
			Object.entries(vars || {}).reduce(
				(acc, [name, value]) => acc.replace(`{${name}}`, value),
				key,
			),
	},
}

const stubs = {
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note-card" :data-type="type"><slot /></div>',
	},
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input class="password-field" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

/**
 * A request row as the store's officer view carries it.
 *
 * @param {object} overrides Fields to change.
 * @return {object}
 */
function request(overrides = {}) {
	return {
		id: 'r1',
		userId: 'alice',
		purpose: 'password',
		approvals: 1,
		threshold: 2,
		phrase: 'apple banana cherry',
		status: 'pending',
		approvedByMe: false,
		handedOff: false,
		...overrides,
	}
}

describe('RecoveryOfficerPanel', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useAccountRecoveryStore()
		store.fetchOfficer = vi.fn().mockResolvedValue()
		store.createKey = vi.fn().mockResolvedValue()
		store.approve = vi.fn().mockResolvedValue()
		store.decline = vi.fn().mockResolvedValue()
		store.handOff = vi.fn().mockResolvedValue()
	})

	/**
	 * Mount with the given officer view and let `created` settle.
	 *
	 * @param {object|null} officer The officer view.
	 * @return {Promise<object>}
	 */
	async function mountPanel(officer) {
		store.officer = officer
		const wrapper = mount(RecoveryOfficerPanel, {
			global: { stubs, mixins: [substitutingT] },
		})
		await flush()
		return wrapper
	}

	it('loads the officer view on creation and shows nothing to a non-officer', async () => {
		const wrapper = await mountPanel({ officer: false })

		expect(store.fetchOfficer).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="recovery-officer"]').exists()).toBe(
			false,
		)
	})

	it('shows nothing and does not throw when the officer view cannot load', async () => {
		store.fetchOfficer = vi.fn().mockRejectedValue(new Error('403'))
		const wrapper = await mountPanel(null)

		expect(store.fetchOfficer).toHaveBeenCalledTimes(1)
		expect(wrapper.html()).not.toContain('recovery-officer')
	})

	it('offers to create the key until one exists, and creates it on click', async () => {
		let release
		store.createKey = vi.fn(
			() => new Promise((resolve) => {
				release = resolve
			}),
		)
		const wrapper = await mountPanel({ officer: true, key: null, requests: [] })

		expect(wrapper.text()).toContain('Create the recovery key.')
		expect(wrapper.text()).not.toContain('No one is asking')
		const button = wrapper.find('[data-testid="recovery-create-key"]')
		await button.trigger('click')

		expect(store.createKey).toHaveBeenCalledTimes(1)
		expect(button.attributes('disabled')).toBeDefined()
		release()
		await flush()
		expect(button.attributes('disabled')).toBeUndefined()
	})

	it('says no one is asking when the key exists and there are no requests', async () => {
		const wrapper = await mountPanel({ officer: true, key: { id: 'k' }, requests: [] })

		expect(wrapper.find('[data-testid="recovery-create-key"]').exists()).toBe(
			false,
		)
		expect(wrapper.text()).toContain('No one is asking to recover their account.')
	})

	it('shows the user, the approval count and the phrase to compare', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request()],
		})

		const row = wrapper.find('[data-testid="recovery-request-r1"]')
		expect(row.text()).toContain('alice')
		expect(row.text()).toContain('1 of 2 approvals')
		expect(row.text()).toContain(
			'Ask alice which words they see, by phone or in person. They must be:',
		)
		expect(row.find('.recovery-officer__phrase').text()).toBe(
			'apple banana cherry',
		)
		expect(row.text()).not.toContain('asks to unlock a new device once')
		expect(wrapper.text()).not.toContain('No one is asking')
	})

	it('says a device request keeps the master password', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request({ purpose: 'device', userId: 'bob' })],
		})

		expect(wrapper.text()).toContain(
			'bob asks to unlock a new device once. They keep their master password.',
		)
	})

	it('approves only with a typed master password and clears it afterwards', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request()],
		})

		const approve = wrapper.find('[data-testid="recovery-approve-r1"]')
		expect(approve.attributes('disabled')).toBeDefined()

		await wrapper.find('.password-field').setValue('hunter2')
		expect(approve.attributes('disabled')).toBeUndefined()
		await approve.trigger('click')
		await flush()

		expect(store.approve).toHaveBeenCalledTimes(1)
		expect(store.approve.mock.calls[0][0]).toMatchObject({ id: 'r1', userId: 'alice' })
		expect(store.approve.mock.calls[0][1]).toBe('hunter2')
		expect(wrapper.find('.password-field').element.value).toBe('')
		expect(approve.attributes('disabled')).toBeDefined()
	})

	it('declines a pending request by its id', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request()],
		})

		expect(wrapper.find('[data-testid="recovery-handoff-r1"]').exists()).toBe(
			false,
		)
		await wrapper.find('[data-testid="recovery-decline-r1"]').trigger('click')
		await flush()

		expect(store.decline).toHaveBeenCalledWith('r1')
	})

	it('does not ask for approval again once this officer approved a pending request', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request({ approvedByMe: true })],
		})

		expect(wrapper.find('[data-testid="recovery-approve-r1"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('.password-field').exists()).toBe(false)
		expect(wrapper.find('[data-testid="recovery-decline-r1"]').exists()).toBe(
			true,
		)
	})

	it('hands the key over once approved by this officer, and offers no decline', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request({ status: 'approved', approvedByMe: true, approvals: 2 })],
		})

		expect(wrapper.find('[data-testid="recovery-decline-r1"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="recovery-approve-r1"]').exists()).toBe(
			false,
		)
		await wrapper.find('[data-testid="recovery-handoff-r1"]').trigger('click')
		await flush()

		expect(store.handOff).toHaveBeenCalledTimes(1)
		expect(store.handOff.mock.calls[0][0]).toMatchObject({ id: 'r1', status: 'approved' })
	})

	it('offers no hand-over once handed off or when another officer approved', async () => {
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [
				request({ id: 'r1', status: 'approved', approvedByMe: true, handedOff: true }),
				request({ id: 'r2', status: 'approved', approvedByMe: false }),
			],
		})

		expect(wrapper.find('[data-testid="recovery-handoff-r1"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="recovery-handoff-r2"]').exists()).toBe(
			false,
		)
	})

	it("shows the server's message when an action fails and keeps the typed password", async () => {
		store.approve = vi
			.fn()
			.mockRejectedValue({ response: { data: { message: 'Wrong master password' } } })
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request()],
		})

		await wrapper.find('.password-field').setValue('wrong')
		await wrapper.find('[data-testid="recovery-approve-r1"]').trigger('click')
		await flush()

		const note = wrapper.find('.note-card')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Wrong master password')
		expect(wrapper.find('.password-field').element.value).toBe('wrong')
	})

	it("falls back to the error's own message and clears it on the next action", async () => {
		store.decline = vi
			.fn()
			.mockRejectedValueOnce(new Error('Network down'))
			.mockResolvedValueOnce()
		const wrapper = await mountPanel({
			officer: true,
			key: { id: 'k' },
			requests: [request()],
		})

		await wrapper.find('[data-testid="recovery-decline-r1"]').trigger('click')
		await flush()
		expect(wrapper.find('.note-card').text()).toBe('Network down')

		await wrapper.find('[data-testid="recovery-decline-r1"]').trigger('click')
		await flush()
		expect(wrapper.find('.note-card').exists()).toBe(false)
		expect(store.decline).toHaveBeenCalledTimes(2)
	})
})
