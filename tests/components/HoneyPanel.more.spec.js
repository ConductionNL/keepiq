/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The honey (decoy) panel on the secret detail, driven through the real honey
 * store with only HTTP mocked: it loads the flag and its placement note, loads
 * alerts only for a flagged secret and lists only this secret's, flags with
 * the typed note and unflags, re-saves the note on blur, and acknowledges or
 * snoozes (24h) an alert. Store errors show in an error card.
 * (`honeySnoozed.spec.js` covers the snooze clock on its own.)
 *
 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
 * @spec openspec/specs/honey-credentials/spec.md#requirement-any-access-to-a-honey-secret-raises-a-high-severity-alert
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HoneyPanel from '../../src/components/HoneyPanel.vue'
import { useHoneyStore } from '../../src/store/modules/honey.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const STATUS_URL = '/apps/keepiq/api/v1/secrets/secret-1/honey'
const ALERTS_URL = '/apps/keepiq/api/v1/honey/alerts'

const stubs = {
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note-card" :data-type="type"><slot /></div>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template:
			'<label><input class="switch" type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue', 'blur'],
		template:
			'<input class="note-field" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @blur="$emit(\'blur\')" />',
	},
}

const ALERTS = [
	{
		id: 'a1',
		secretId: 'secret-1',
		accessorId: 'mallory',
		ip: '10.0.0.7',
		channel: 'app',
		accessCount: 3,
		accessedAt: '2026-06-01T08:00:00Z',
		acknowledgedAt: null,
		snoozedUntil: null,
	},
	{
		id: 'a2',
		secretId: 'secret-1',
		accessorId: null,
		ip: null,
		channel: 'link',
		accessCount: 1,
		accessedAt: null,
		acknowledgedAt: '2026-06-01T09:00:00Z',
		snoozedUntil: '2999-01-01T00:00:00Z',
	},
	{
		id: 'a3',
		secretId: 'other-secret',
		accessorId: 'eve',
		channel: 'api',
		accessCount: 9,
		accessedAt: '2026-06-01T08:00:00Z',
	},
]

/**
 * Answer the panel's GETs.
 *
 * @param {object} status The honey status answer.
 * @param {Array<object>} alerts The alerts answer.
 * @return {object} The spy.
 */
function serve(status, alerts = ALERTS) {
	return vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url === STATUS_URL) {
			return { data: status }
		}
		if (url === ALERTS_URL) {
			return { data: alerts }
		}
		throw new Error(`unexpected GET ${url}`)
	})
}

/**
 * Mount the panel and let `created` settle.
 *
 * @return {Promise<object>}
 */
async function mountPanel() {
	const wrapper = mount(HoneyPanel, {
		props: { secretId: 'secret-1' },
		global: { stubs },
	})
	await flush()
	return wrapper
}

describe('HoneyPanel', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('loads an unflagged secret without loading alerts', async () => {
		const get = serve({ flagged: false, flag: null })
		const wrapper = await mountPanel()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith(STATUS_URL)
		expect(wrapper.find('.switch').element.checked).toBe(false)
		expect(wrapper.find('.note-field').exists()).toBe(false)
		expect(wrapper.find('[data-testid="honey-alerts"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="honey-no-alerts"]').exists()).toBe(false)
	})

	it("loads a flagged secret's note and lists only this secret's alerts", async () => {
		const get = serve({ flagged: true, flag: { note: 'in the wiki' } })
		const wrapper = await mountPanel()

		expect(get).toHaveBeenCalledWith(ALERTS_URL)
		expect(wrapper.find('.switch').element.checked).toBe(true)
		expect(wrapper.find('.note-field').element.value).toBe('in the wiki')

		const rows = wrapper.findAll('[data-testid="honey-alerts"] tbody tr')
		expect(rows).toHaveLength(2)
		expect(wrapper.find('[data-testid="honey-alert-a3"]').exists()).toBe(false)

		const first = wrapper.find('[data-testid="honey-alert-a1"]')
		expect(first.text()).toContain('mallory')
		expect(first.text()).toContain('10.0.0.7')
		expect(first.text()).toContain('app')
		expect(first.text()).toContain('3')
		expect(first.text()).toContain(
			new Date('2026-06-01T08:00:00Z').toLocaleString(),
		)
		expect(first.find('[data-testid="honey-ack-a1"]').exists()).toBe(true)
		expect(first.find('[data-testid="honey-snooze-a1"]').exists()).toBe(true)

		const second = wrapper.find('[data-testid="honey-alert-a2"]')
		expect(second.text()).toContain('anonymous')
		expect(second.text()).toContain('—')
		expect(second.text()).toContain('handled')
		expect(second.text()).toContain('snoozed')
		expect(second.find('[data-testid="honey-ack-a2"]').exists()).toBe(false)
		expect(second.find('[data-testid="honey-snooze-a2"]').exists()).toBe(false)
	})

	it('says the tripwire is armed when this secret has no alerts', async () => {
		serve({ flagged: true, flag: { note: '' } }, [ALERTS[2]])
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="honey-alerts"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="honey-no-alerts"]').exists()).toBe(true)
	})

	it('flags the secret with the note, then loads the alerts', async () => {
		const get = serve({ flagged: false, flag: null })
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { note: '' } })
		const wrapper = await mountPanel()

		await wrapper.find('.switch').setValue(true)
		await flush()

		expect(post).toHaveBeenCalledWith(STATUS_URL, { note: '' })
		expect(get).toHaveBeenLastCalledWith(ALERTS_URL)
		expect(wrapper.find('.note-field').exists()).toBe(true)
		expect(wrapper.find('[data-testid="honey-alerts"]').exists()).toBe(true)
	})

	it('unflags the secret and hides the decoy details', async () => {
		serve({ flagged: true, flag: { note: 'n' } })
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = await mountPanel()

		await wrapper.find('.switch').setValue(false)
		await flush()

		expect(del).toHaveBeenCalledWith(STATUS_URL)
		expect(wrapper.find('.switch').element.checked).toBe(false)
		expect(wrapper.find('.note-field').exists()).toBe(false)
	})

	it('saves the edited note on blur', async () => {
		serve({ flagged: true, flag: { note: 'old' } })
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { note: 'behind the printer' } })
		const wrapper = await mountPanel()

		const field = wrapper.find('.note-field')
		await field.setValue('behind the printer')
		await field.trigger('blur')
		await flush()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(STATUS_URL, { note: 'behind the printer' })
	})

	it('does not save a note once the secret is no longer flagged', async () => {
		serve({ flagged: true, flag: { note: 'old' } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = await mountPanel()

		const field = wrapper.find('.note-field')
		await field.setValue('typed while unflagging')
		// The switch went off elsewhere (another tab, the store) before the
		// field lost focus: the blur must not re-flag the secret.
		useHoneyStore().status = { flagged: false, flag: null }
		await field.trigger('blur')
		await flush()

		expect(post).not.toHaveBeenCalled()
	})

	it('acknowledges an alert and shows it handled', async () => {
		serve({ flagged: true, flag: { note: '' } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { ...ALERTS[0], acknowledgedAt: '2026-06-01T10:00:00Z' },
		})
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="honey-ack-a1"]').trigger('click')
		await flush()

		expect(post).toHaveBeenCalledWith(`${ALERTS_URL}/a1/acknowledge`)
		const row = wrapper.find('[data-testid="honey-alert-a1"]')
		expect(row.find('[data-testid="honey-ack-a1"]').exists()).toBe(false)
		expect(row.text()).toContain('handled')
	})

	it('snoozes an alert for 24 hours and shows it snoozed', async () => {
		serve({ flagged: true, flag: { note: '' } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { ...ALERTS[0], snoozedUntil: '2999-01-01T00:00:00Z' },
		})
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="honey-snooze-a1"]').trigger('click')
		await flush()

		expect(post).toHaveBeenCalledWith(`${ALERTS_URL}/a1/snooze`, { hours: 24 })
		const row = wrapper.find('[data-testid="honey-alert-a1"]')
		expect(row.find('[data-testid="honey-snooze-a1"]').exists()).toBe(false)
		expect(row.text()).toContain('snoozed')
	})

	it("shows the server's message when the flag cannot be loaded", async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: { data: { message: 'Only the owner can see this' } },
		})
		const wrapper = await mountPanel()

		const note = wrapper.find('.note-card')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Only the owner can see this')
		expect(wrapper.find('.switch').element.checked).toBe(false)
		expect(wrapper.find('.note-field').exists()).toBe(false)
	})
})
