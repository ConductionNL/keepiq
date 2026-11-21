/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OwnershipFindings through its template: picking a team folder in the
 * select enables that row's Move button, which moves the secret and drops
 * the row; a load failure leaves the list hidden with the error kept; and a
 * response without data reads as no findings.
 *
 * @spec openspec/specs/vault-policies/spec.md#requirement-users-see-personal-items-that-break-the-ownership-policy
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OwnershipFindings from '../../src/components/OwnershipFindings.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

const stubs = {
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'inputLabel'],
		emits: ['update:modelValue'],
		template:
			'<button class="pick" @click="$emit(\'update:modelValue\', options[0])">{{ modelValue ? modelValue.label : \'\' }}</button>',
	},
}

const FINDING = { id: 'old-login', name: 'Bank', typeId: 't', folderId: 'private' }

/**
 * Mount with the policy applying and these responses for the two lists.
 *
 * @param {Function} lists Returns the response (or throws) for a list URL.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(lists) {
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.includes('/settings/policy')) {
			return { data: { vault_org_ownership: true } }
		}
		return lists(url)
	})
	const teamFolderStore = useTeamFolderStore()
	teamFolderStore.fetchTeamFolders = vi.fn(async () => {
		teamFolderStore.owned = [
			{ id: 'tf-mine', folderId: 'folder-mine', folderName: 'Mine' },
		]
	})
	const wrapper = mount(OwnershipFindings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('OwnershipFindings wiring', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('enables Move once a team folder is picked, then moves and drops the row', async () => {
		const wrapper = await mountWith(async (url) =>
			url.includes('ownership-findings') ? { data: [FINDING] } : { data: [] },
		)
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({})
		const fanOut = vi
			.spyOn(useTeamFolderStore(), 'runFanOut')
			.mockResolvedValue({ refused: [] })

		const moveButton = wrapper.find('[data-testid="ownership-move-old-login"]')
		expect(moveButton.attributes('disabled')).toBeDefined()

		await wrapper.find('.pick').trigger('click')
		expect(wrapper.find('.pick').text()).toBe('Mine')
		expect(
			wrapper
				.find('[data-testid="ownership-move-old-login"]')
				.attributes('disabled'),
		).toBeUndefined()

		await wrapper
			.find('[data-testid="ownership-move-old-login"]')
			.trigger('click')
		await flushPromises()

		expect(update).toHaveBeenCalledWith('old-login', {
			folderId: 'folder-mine',
		})
		expect(fanOut).toHaveBeenCalledWith('tf-mine')
		expect(wrapper.find('[data-testid="ownership-findings"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="ownership-findings-error"]').exists(),
		).toBe(false)
	})

	it('reads responses without data as no findings and no writable folders', async () => {
		const wrapper = await mountWith(async () => ({}))
		expect(wrapper.vm.findings).toEqual([])
		expect(wrapper.vm.contributable).toEqual([])
		expect(wrapper.vm.targetOptions).toEqual([
			{
				label: 'Mine',
				kind: 'owner',
				teamFolderId: 'tf-mine',
				folderId: 'folder-mine',
			},
		])
		expect(wrapper.find('[data-testid="ownership-findings"]').exists()).toBe(
			false,
		)
	})

	it("keeps the server's message when loading fails, and lists nothing", async () => {
		const wrapper = await mountWith(async () => {
			throw { response: { data: { message: 'Policy service down' } } }
		})
		expect(wrapper.vm.error).toBe('Policy service down')
		expect(wrapper.vm.findings).toEqual([])
		expect(wrapper.find('[data-testid="ownership-findings"]').exists()).toBe(
			false,
		)
	})

	it("keeps the error's own message when the server sent none", async () => {
		const wrapper = await mountWith(async () => {
			throw new Error('Network Error')
		})
		expect(wrapper.vm.error).toBe('Network Error')
	})
})
