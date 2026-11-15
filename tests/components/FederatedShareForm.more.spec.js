/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FederatedShareForm around the verifier (which FederatedShareForm.spec.js
 * runs for real): the lookup and the browser encryption are stubbed at the
 * store so this covers the form's own flow —
 *
 *   - the share list with each status in words, revoke on every row that
 *     is not already being withdrawn, and a revoke that removes the row or
 *     shows why it failed;
 *   - a verified share that emits the created row, clears the form and
 *     reloads the list;
 *   - no share without a verified recipient;
 *   - editing the typed account drops a verified recipient;
 *   - refusal texts for the server's codes, with a generic fallback.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FederatedShareForm from '../../src/components/share/FederatedShareForm.vue'
import { useFederatedShareStore } from '../../src/store/modules/federatedShare.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

const BOB = 'bob@cloud.partner.example'
const RECIPIENT = { cloudId: BOB, fingerprint: 'AA:BB', valueFields: [] }

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
}

const SHARES = [
	{ id: 'fs-1', recipientCloudId: BOB, status: 'active' },
	{ id: 'fs-2', recipientCloudId: 'carol@cloud.partner.example', status: 'suspended' },
	{ id: 'fs-3', recipientCloudId: 'dan@cloud.partner.example', status: 'failed' },
	{ id: 'fs-4', recipientCloudId: 'eve@cloud.partner.example', status: 'revoked' },
	{ id: 'fs-5', recipientCloudId: 'fay@cloud.partner.example', status: 'pending' },
]

let get
let store

/**
 * Mount the form for secret src and let the share list load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountForm() {
	const wrapper = mount(FederatedShareForm, {
		props: { secretId: 'src' },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

/**
 * Type the account and run the (stubbed) check.
 *
 * @param {object} wrapper The mounted form.
 * @return {Promise<void>}
 */
async function checkBob(wrapper) {
	await wrapper.find('[data-testid="federated-share-cloud-id"]').setValue(BOB)
	await wrapper.find('[data-testid="federated-share-check"]').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	get = vi.spyOn(axios, 'get').mockResolvedValue({ data: SHARES.map((s) => ({ ...s })) })
	store = useFederatedShareStore()
	vi.spyOn(store, 'lookup').mockResolvedValue({ ...RECIPIENT })
})

describe('FederatedShareForm share list', () => {
	it('lists each share with its status in words and revoke where it still applies', async () => {
		const wrapper = await mountForm()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/src/federated-shares')
		const states = wrapper.findAll('[data-testid="federated-share-state"]').map((s) => s.text())
		expect(states).toEqual([
			'Shared',
			'Stopped: their organisation could not be reached, or their certificate or the partnership changed. Revoke it or share again.',
			'Their organisation did not get the last change. Revoke it or share again.',
			'Being withdrawn',
			'pending',
		])
		expect(
			wrapper.find('[data-testid="federated-share-row-fs-4"] [data-testid="federated-share-revoke"]').exists(),
		).toBe(false)
		expect(wrapper.findAll('[data-testid="federated-share-revoke"]')).toHaveLength(4)
	})

	it('revokes a share and drops its row', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = await mountForm()

		await wrapper
			.find('[data-testid="federated-share-row-fs-2"] [data-testid="federated-share-revoke"]')
			.trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/federated-shares/fs-2')
		expect(wrapper.find('[data-testid="federated-share-row-fs-2"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="federated-share-error"]').exists()).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('says why a revoke failed and keeps the row', async () => {
		vi.spyOn(axios, 'delete').mockRejectedValue({
			response: { data: { message: 'partner_unreachable' } },
		})
		const wrapper = await mountForm()

		await wrapper
			.find('[data-testid="federated-share-row-fs-1"] [data-testid="federated-share-revoke"]')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="federated-share-error"]').text()).toBe(
			'The other organisation did not answer. Try again later.',
		)
		expect(wrapper.find('[data-testid="federated-share-row-fs-1"]').exists()).toBe(true)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('still renders the form when the list cannot be loaded', async () => {
		get.mockRejectedValue(new Error('500'))
		const wrapper = await mountForm()

		expect(wrapper.find('[data-testid="federated-share-list"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="federated-share-check"]').exists()).toBe(true)
	})
})

describe('FederatedShareForm sharing', () => {
	it('shares with the verified recipient, emits the row, clears the form and reloads the list', async () => {
		const secret = { name: 'Portal', key: 'pw' }
		const fetchSecret = vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue(secret)
		const row = { id: 'fs-9', recipientCloudId: BOB, status: 'active' }
		const share = vi.spyOn(store, 'share').mockResolvedValue(row)
		const wrapper = await mountForm()
		await checkBob(wrapper)

		expect(store.lookup).toHaveBeenCalledWith(BOB)
		expect(wrapper.find('[data-testid="federated-share-fingerprint"]').text()).toBe('AA:BB')

		await wrapper.find('[data-testid="federated-share-submit"]').trigger('click')
		await flushPromises()

		expect(fetchSecret).toHaveBeenCalledWith('src')
		expect(share).toHaveBeenCalledWith('src', secret, RECIPIENT)
		expect(wrapper.emitted('shared')).toEqual([[row]])
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.cloudId).toBe('')
		expect(wrapper.find('[data-testid="federated-share-verified"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="federated-share-done"]').exists()).toBe(true)
		expect(wrapper.vm.sharedWith).toBe(BOB)
	})

	it('shares nothing without a verified recipient', async () => {
		const fetchSecret = vi.spyOn(useSecretStore(), 'fetchSecret')
		const wrapper = await mountForm()

		await wrapper.vm.share()

		expect(fetchSecret).not.toHaveBeenCalled()
		expect(wrapper.emitted('shared')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('drops the verified recipient once the typed account changes', async () => {
		const wrapper = await mountForm()
		await checkBob(wrapper)
		expect(wrapper.find('[data-testid="federated-share-verified"]').exists()).toBe(true)

		await wrapper.find('[data-testid="federated-share-cloud-id"]').setValue('carol@cloud.partner.example')

		expect(wrapper.find('[data-testid="federated-share-verified"]').exists()).toBe(false)
	})

	it.each([
		['unknown_recipient', 'No one with that account can receive secrets from you.'],
		['delivery_failed', 'The other organisation did not answer. Try again later.'],
		['already_shared', 'This secret is already shared with that account.'],
		['no_such_code', 'Something went wrong. Try again.'],
	])('says what the %s refusal of a share means', async (message, shown) => {
		vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({ key: 'pw' })
		vi.spyOn(store, 'share').mockRejectedValue({ response: { data: { message } } })
		const wrapper = await mountForm()
		await checkBob(wrapper)

		await wrapper.find('[data-testid="federated-share-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="federated-share-error"]').text()).toBe(shown)
		expect(wrapper.emitted('shared')).toBeUndefined()
		expect(wrapper.find('[data-testid="federated-share-verified"]').exists()).toBe(true)
	})
})
