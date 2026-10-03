/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Incoming from other organisations" (keepiq#789, sharing-federated-recipients
 * task 3.2): the list shows what partners shared, and Accept and Decline
 * call the server for exactly that share.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import IncomingSharesView from '../../src/views/IncomingSharesView.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * A `t` that fills `{placeholders}`, so the sender shows in the text.
 *
 * @param {string} _app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string}
 */
const interpolate = (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, name) => vars[name] ?? match)

const stubs = {
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcEmptyContent: {
		props: ['name', 'description'],
		template: '<div v-bind="$attrs">{{ name }}</div>',
	},
	NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
}

const PENDING = {
	id: 'in-1',
	senderCloudId: 'alice@cloud.city.example',
	name: 'Supplier portal',
	secretId: null,
	status: 'pending',
}

/**
 * Mount the view over this list.
 *
 * @param {Array<object>} list The inbound shares.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(list) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: list })
	const wrapper = mount(IncomingSharesView, { global: { stubs, mixins: [{ methods: { t: interpolate } }] } })
	await flush()
	return wrapper
}

describe('IncomingSharesView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('says nothing was shared when the list is empty', async () => {
		const wrapper = await mountWith([])

		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/apps/keepiq/api/v1/federation/incoming'))
		expect(wrapper.find('[data-testid="incoming-shares-empty"]').exists()).toBe(true)
	})

	it('shows each share with its sender, and the answers only while it is pending', async () => {
		const wrapper = await mountWith([
			PENDING,
			{ ...PENDING, id: 'in-2', status: 'accepted', secretId: 'copy-2' },
			{ ...PENDING, id: 'in-3', status: 'declined' },
		])

		const pending = wrapper.find('[data-testid="incoming-share-in-1"]')
		expect(pending.text()).toContain('Supplier portal')
		expect(pending.text()).toContain('alice@cloud.city.example')
		expect(pending.find('[data-testid="incoming-share-accept"]').exists()).toBe(true)
		expect(pending.find('[data-testid="incoming-share-decline"]').exists()).toBe(true)

		const accepted = wrapper.find('[data-testid="incoming-share-in-2"]')
		expect(accepted.find('[data-testid="incoming-share-accept"]').exists()).toBe(false)
		expect(accepted.find('[data-testid="incoming-share-open"]').exists()).toBe(true)

		const declined = wrapper.find('[data-testid="incoming-share-in-3"]')
		expect(declined.findAll('button')).toHaveLength(0)
	})

	it('accepts exactly that share and shows it in the vault', async () => {
		const wrapper = await mountWith([PENDING])
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { ...PENDING, status: 'accepted', secretId: 'copy-1' } })

		await wrapper.find('[data-testid="incoming-share-accept"]').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/v1/federation/incoming/in-1/accept'))
		expect(wrapper.find('[data-testid="incoming-share-accept"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="incoming-share-open"]').exists()).toBe(true)
	})

	it('declines exactly that share', async () => {
		const wrapper = await mountWith([PENDING])
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { ...PENDING, status: 'declined' } })

		await wrapper.find('[data-testid="incoming-share-decline"]').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/v1/federation/incoming/in-1/decline'))
		expect(wrapper.find('[data-testid="incoming-share-status"]').text()).toBe('Declined')
	})

	it('explains a pull the other organisation refused, and keeps the answers', async () => {
		const wrapper = await mountWith([PENDING])
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { message: 'pull_failed' } } })

		await wrapper.find('[data-testid="incoming-share-accept"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="incoming-shares-error"]').text()).toContain('did not hand over the secret')
		expect(wrapper.find('[data-testid="incoming-share-accept"]').exists()).toBe(true)
	})
})
