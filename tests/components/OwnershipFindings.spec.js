/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The health report's "Not in a team folder" list (admin-vault-policies
 * §4.4): an owner move changes the folder and shares it; a contribution
 * move contributes a copy and only then removes the personal secret.
 *
 * @spec openspec/changes/admin-vault-policies/tasks.md#4.4
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
		template: '<div />',
	},
}

/**
 * Mount with the given policy.
 *
 * @param {boolean} applies Whether the ownership policy applies.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(applies) {
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.includes('/settings/policy')) {
			return { data: { vault_org_ownership: applies } }
		}
		if (url.includes('ownership-findings')) {
			return {
				data: [
					{
						id: 'old-login',
						name: 'Bank',
						typeId: 't',
						folderId: 'private',
					},
				],
			}
		}
		if (url.includes('contributable')) {
			return {
				data: [
					{
						teamFolderId: 'tf-ops',
						folderId: 'folder-ops',
						folderName: 'Ops',
					},
				],
			}
		}
		return { data: { id: 'old-login', key: 'CIPHER' } }
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

describe('OwnershipFindings', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('lists the finding with owned and writable team folders', async () => {
		const wrapper = await mountWith(true)

		expect(
			wrapper.find('[data-testid="ownership-finding-old-login"]').exists(),
		).toBe(true)
		expect(
			wrapper.vm.targetOptions.map((option) => [option.kind, option.label]),
		).toEqual([
			['owner', 'Mine'],
			['contribute', 'Ops'],
		])
	})

	it('shows nothing when the policy does not apply', async () => {
		const wrapper = await mountWith(false)

		expect(wrapper.find('[data-testid="ownership-findings"]').exists()).toBe(
			false,
		)
	})

	it('an owner move changes the folder and shares it', async () => {
		const wrapper = await mountWith(true)
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({})
		const fanOut = vi
			.spyOn(useTeamFolderStore(), 'runFanOut')
			.mockResolvedValue({ created: 1 })

		wrapper.vm.targets['old-login'] = wrapper.vm.targetOptions[0]
		await wrapper.vm.move({ id: 'old-login' })

		expect(update).toHaveBeenCalledWith('old-login', { folderId: 'folder-mine' })
		expect(fanOut).toHaveBeenCalledWith('tf-mine')
		expect(wrapper.vm.findings).toEqual([])
	})

	it('a contribution move contributes first and deletes the personal secret after', async () => {
		const wrapper = await mountWith(true)
		const order = []
		const secretStore = useSecretStore()
		vi.spyOn(secretStore, 'decryptSecret').mockResolvedValue({
			name: 'Bank',
			typeId: 't',
			key: 'plain',
		})
		vi.spyOn(secretStore, 'contributeSecret').mockImplementation(async () =>
			order.push('contribute'),
		)
		vi.spyOn(secretStore, 'deleteSecret').mockImplementation(async () =>
			order.push('delete'),
		)

		wrapper.vm.targets['old-login'] = wrapper.vm.targetOptions[1]
		await wrapper.vm.move({ id: 'old-login' })

		expect(secretStore.contributeSecret).toHaveBeenCalledWith(
			'tf-ops',
			expect.objectContaining({ name: 'Bank', key: 'plain' }),
		)
		expect(order).toEqual(['contribute', 'delete'])
	})

	it('keeps the personal secret when the contribution fails', async () => {
		const wrapper = await mountWith(true)
		const secretStore = useSecretStore()
		vi.spyOn(secretStore, 'decryptSecret').mockResolvedValue({
			name: 'Bank',
			typeId: 't',
			key: 'plain',
		})
		vi.spyOn(secretStore, 'contributeSecret').mockRejectedValue(new Error('403'))
		const remove = vi.spyOn(secretStore, 'deleteSecret')

		wrapper.vm.targets['old-login'] = wrapper.vm.targetOptions[1]
		await wrapper.vm.move({ id: 'old-login' })

		expect(remove).not.toHaveBeenCalled()
		expect(wrapper.vm.findings).toHaveLength(1)
	})
})
