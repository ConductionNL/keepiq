/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rotation & expiry panel on the secret detail view
 * (rotation-expiry-policies §7.1/§7.3). Pins: the expiry pair is loaded on
 * mount and seeds the date editor, the expiry chip is phrased and coloured
 * by days left, the open flag is labelled by its reason, the owner-only
 * actions send exactly the secret / flag id to the rotation store, a
 * "requires rotation" answer from mark-rotated surfaces the warning, and
 * every failure lands in the error note card.
 *
 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-per-secret-expiry
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RotationPanel from '../../src/components/RotationPanel.vue'
import { useRotationStore } from '../../src/store/modules/rotation.js'

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
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
}

const PAST = '2000-01-01T00:00:00Z'
const FUTURE = '2999-06-15T00:00:00Z'

/**
 * Mount the panel and let its mounted load settle.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(props = {}) {
	const wrapper = mount(RotationPanel, {
		props: { secretId: 's1', canManage: true, ...props },
		global: { stubs, mixins: [substitutingT] },
	})
	await flush()
	await flush()
	return wrapper
}

const localDate = (iso) => new Date(Date.parse(iso)).toLocaleDateString()

describe('RotationPanel', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useRotationStore()
		store.getExpiry = vi
			.fn()
			.mockResolvedValue({ expiresAt: FUTURE, effectiveExpiry: FUTURE })
		store.fetchFlags = vi.fn().mockResolvedValue()
		store.setExpiry = vi.fn()
		store.flagSecrets = vi.fn().mockResolvedValue()
		store.markRotated = vi.fn()
		store.dismissFlag = vi.fn().mockResolvedValue()
	})

	it('loads the expiry for its secret, seeds the date editor and fetches flags', async () => {
		const wrapper = await mountPanel()

		expect(store.getExpiry).toHaveBeenCalledWith('s1')
		expect(store.fetchFlags).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="expiry-input"]').element.value).toBe(
			'2999-06-15',
		)
		const chip = wrapper.find('[data-testid="expiry-chip"]')
		expect(chip.text()).toBe(`Expires ${localDate(FUTURE)}`)
		expect(chip.classes()).toContain('rotation-panel__chip--ok')
		expect(wrapper.find('[data-testid="expiry-clear"]').exists()).toBe(true)
	})

	it('phrases a passed expiry as "Expired" with the due style', async () => {
		store.getExpiry.mockResolvedValue({ expiresAt: null, effectiveExpiry: PAST })
		const wrapper = await mountPanel()

		const chip = wrapper.find('[data-testid="expiry-chip"]')
		expect(chip.text()).toBe(`Expired ${localDate(PAST)}`)
		expect(chip.classes()).toContain('rotation-panel__chip--due')
		// No stored expiry: the editor is empty and there is nothing to clear.
		expect(wrapper.find('[data-testid="expiry-input"]').element.value).toBe('')
		expect(wrapper.find('[data-testid="expiry-clear"]').exists()).toBe(false)
	})

	it('marks an expiry within 30 days as soon', async () => {
		const soon = new Date(Date.now() + 10 * 86400000).toISOString()
		store.getExpiry.mockResolvedValue({ expiresAt: soon, effectiveExpiry: soon })
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="expiry-chip"]').classes()).toContain(
			'rotation-panel__chip--soon',
		)
	})

	it('shows "No expiry" without a chip when nothing applies', async () => {
		store.getExpiry.mockResolvedValue({ expiresAt: null, effectiveExpiry: null })
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="expiry-chip"]').exists()).toBe(false)
		expect(wrapper.find('.rotation-panel__none').text()).toBe('No expiry')
	})

	it('hides every editing control from a viewer who cannot manage', async () => {
		store.flags = [{ id: 'f1', secretId: 's1', reason: 'user_flagged' }]
		const wrapper = await mountPanel({ canManage: false })

		expect(wrapper.find('[data-testid="rotation-flag"]').exists()).toBe(true)
		for (const id of [
			'rotation-mark-rotated',
			'rotation-dismiss',
			'expiry-input',
			'expiry-save',
			'expiry-clear',
			'rotation-flag-now',
		]) {
			expect(wrapper.find(`[data-testid="${id}"]`).exists()).toBe(false)
		}
	})

	it('surfaces a load failure using the server message', async () => {
		store.getExpiry.mockRejectedValue({
			response: { data: { message: 'Not your secret' } },
		})
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
			'Not your secret',
		)
		expect(store.fetchFlags).not.toHaveBeenCalled()
	})

	it('falls back to a fixed load message when the error has none', async () => {
		store.getExpiry.mockRejectedValue({})
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
			'Failed to load expiry',
		)
	})

	describe('flag label', () => {
		it.each([
			['user_flagged', 'Rotation requested'],
			['policy_expiry', 'Rotation due — expired'],
			['suite_compromise', 'Rotation due — possible compromise'],
			['something_else', 'Rotation due'],
		])('labels a %s flag as "%s"', async (reason, label) => {
			store.flags = [{ id: 'f1', secretId: 's1', reason }]
			const wrapper = await mountPanel()

			expect(
				wrapper.find('[data-testid="rotation-flag"] .rotation-panel__chip').text(),
			).toBe(label)
			// An open flag replaces the manual "flag for rotation" action.
			expect(wrapper.find('[data-testid="rotation-flag-now"]').exists()).toBe(
				false,
			)
		})

		it('ignores flags that belong to another secret', async () => {
			store.flags = [{ id: 'f9', secretId: 'other', reason: 'user_flagged' }]
			const wrapper = await mountPanel()

			expect(wrapper.find('[data-testid="rotation-flag"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="rotation-flag-now"]').exists()).toBe(
				true,
			)
		})
	})

	describe('set expiry', () => {
		it('sends the edited date as midnight UTC and shows the new expiry', async () => {
			store.setExpiry.mockResolvedValue({
				secret: { expiresAt: '2999-12-31T00:00:00Z' },
				effectiveExpiry: '2999-12-31T00:00:00Z',
			})
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-input"]').setValue('2999-12-31')
			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()

			expect(store.setExpiry).toHaveBeenCalledWith(
				's1',
				'2999-12-31T00:00:00Z',
			)
			expect(wrapper.find('[data-testid="expiry-chip"]').text()).toBe(
				`Expires ${localDate('2999-12-31T00:00:00Z')}`,
			)
			expect(wrapper.vm.expiresAt).toBe('2999-12-31T00:00:00Z')
		})

		it('keeps the sent date when the response carries no secret', async () => {
			store.setExpiry.mockResolvedValue({ effectiveExpiry: FUTURE })
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-input"]').setValue('2999-07-01')
			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()

			expect(wrapper.vm.expiresAt).toBe('2999-07-01T00:00:00Z')
		})

		it('does nothing without a date', async () => {
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-input"]').setValue('')
			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()

			expect(store.setExpiry).not.toHaveBeenCalled()
		})

		it('shows the error message on failure', async () => {
			store.setExpiry.mockRejectedValue(new Error('Date in the past'))
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()

			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Date in the past',
			)
		})

		it('falls back to a fixed save message', async () => {
			store.setExpiry.mockRejectedValue({})
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()

			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Failed to set expiry',
			)
		})

		it('clears an earlier error after a successful save', async () => {
			store.setExpiry
				.mockRejectedValueOnce(new Error('boom'))
				.mockResolvedValueOnce({ effectiveExpiry: FUTURE })
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').exists()).toBe(true)
			await wrapper.find('[data-testid="expiry-save"]').trigger('click')
			await flush()

			expect(wrapper.find('[data-testid="rotation-error"]').exists()).toBe(false)
		})
	})

	describe('clear expiry', () => {
		it('sends null, empties the editor and shows the policy expiry left', async () => {
			store.setExpiry.mockResolvedValue({ effectiveExpiry: PAST })
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-clear"]').trigger('click')
			await flush()

			expect(store.setExpiry).toHaveBeenCalledWith('s1', null)
			expect(wrapper.find('[data-testid="expiry-input"]').element.value).toBe(
				'',
			)
			expect(wrapper.find('[data-testid="expiry-clear"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="expiry-chip"]').text()).toBe(
				`Expired ${localDate(PAST)}`,
			)
		})

		it('shows the server message on failure', async () => {
			store.setExpiry.mockRejectedValue({
				response: { data: { message: 'Locked by policy' } },
			})
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-clear"]').trigger('click')
			await flush()

			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Locked by policy',
			)
		})

		it('falls back to a fixed clear message', async () => {
			store.setExpiry.mockRejectedValue({})
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="expiry-clear"]').trigger('click')
			await flush()

			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Failed to clear expiry',
			)
		})
	})

	describe('flag for rotation', () => {
		it('flags only this secret id', async () => {
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="rotation-flag-now"]').trigger('click')
			await flush()

			expect(store.flagSecrets).toHaveBeenCalledWith(['s1'])
			expect(wrapper.find('[data-testid="rotation-error"]').exists()).toBe(false)
		})

		it('shows the error on failure, with a fixed fallback', async () => {
			store.flagSecrets.mockRejectedValueOnce(new Error('Forbidden'))
			store.flagSecrets.mockRejectedValueOnce({})
			const wrapper = await mountPanel()

			await wrapper.find('[data-testid="rotation-flag-now"]').trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Forbidden',
			)

			await wrapper.find('[data-testid="rotation-flag-now"]').trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Failed to flag',
			)
		})
	})

	describe('mark rotated', () => {
		beforeEach(() => {
			store.flags = [{ id: 'f1', secretId: 's1', reason: 'policy_expiry' }]
		})

		it('sends the open flag id and shows no warning on a proven rotation', async () => {
			store.markRotated.mockResolvedValue({ requiresRotation: false })
			const wrapper = await mountPanel()

			await wrapper
				.find('[data-testid="rotation-mark-rotated"]')
				.trigger('click')
			await flush()

			expect(store.markRotated).toHaveBeenCalledWith('f1')
			expect(
				wrapper.find('[data-testid="rotation-required-note"]').exists(),
			).toBe(false)
		})

		it('warns that the credential must change first when the server refuses', async () => {
			store.markRotated.mockResolvedValue({ requiresRotation: true })
			const wrapper = await mountPanel()

			await wrapper
				.find('[data-testid="rotation-mark-rotated"]')
				.trigger('click')
			await flush()

			const note = wrapper.find('[data-testid="rotation-required-note"]')
			expect(note.exists()).toBe(true)
			expect(note.text()).toContain('update the secret value first')
		})

		it('shows the error on failure, with a fixed fallback', async () => {
			store.markRotated
				.mockRejectedValueOnce({ response: { data: { message: 'Gone' } } })
				.mockRejectedValueOnce({})
			const wrapper = await mountPanel()
			const button = wrapper.find('[data-testid="rotation-mark-rotated"]')

			await button.trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe('Gone')

			await button.trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Failed to mark rotated',
			)
		})
	})

	describe('dismiss', () => {
		beforeEach(() => {
			store.flags = [{ id: 'f1', secretId: 's1', reason: 'user_flagged' }]
		})

		it('dismisses the open flag id and drops the rotation-required warning', async () => {
			store.markRotated.mockResolvedValue({ requiresRotation: true })
			const wrapper = await mountPanel()
			await wrapper
				.find('[data-testid="rotation-mark-rotated"]')
				.trigger('click')
			await flush()
			expect(
				wrapper.find('[data-testid="rotation-required-note"]').exists(),
			).toBe(true)

			await wrapper.find('[data-testid="rotation-dismiss"]').trigger('click')
			await flush()

			expect(store.dismissFlag).toHaveBeenCalledWith('f1')
			expect(
				wrapper.find('[data-testid="rotation-required-note"]').exists(),
			).toBe(false)
		})

		it('shows the error on failure, with a fixed fallback', async () => {
			store.dismissFlag
				.mockRejectedValueOnce(new Error('Nope'))
				.mockRejectedValueOnce({})
			const wrapper = await mountPanel()
			const button = wrapper.find('[data-testid="rotation-dismiss"]')

			await button.trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe('Nope')

			await button.trigger('click')
			await flush()
			expect(wrapper.find('[data-testid="rotation-error"]').text()).toBe(
				'Failed to dismiss',
			)
		})
	})
})
