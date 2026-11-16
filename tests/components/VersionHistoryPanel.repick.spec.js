/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Changing one's mind in the version comparison: an entry is unpicked again,
 * Compare with fewer than two picks decrypts nothing, and closing the
 * comparison drops the decrypted states.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VersionHistoryPanel from '../../src/components/VersionHistoryPanel.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretVersionStore } from '../../src/store/modules/secretVersion.js'

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
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { template: '<div><slot /></div>' },
	History: true,
	VersionDetailsDialog: true,
	VersionRestoreDialog: true,
	VersionCompareDialog: {
		props: ['comparison'],
		emits: ['close'],
		template:
			'<div v-if="comparison" data-testid="compare-stub"><button data-testid="compare-close" @click="$emit(\'close\')" /></div>',
	},
}

const VERSIONS = [
	{
		id: 'v-3',
		versionNumber: 3,
		actorId: 'alice',
		createdAt: '2026-05-02T10:00:00Z',
	},
	{
		id: 'v-2',
		versionNumber: 2,
		actorId: 'bob',
		createdAt: '2026-04-01T09:30:00Z',
	},
	{
		id: 'v-1',
		versionNumber: 1,
		actorId: 'bob',
		createdAt: '2026-03-01T09:30:00Z',
	},
]

describe('VersionHistoryPanel compare, picking again', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSecretVersionStore()
		store.versions = VERSIONS.map((v) => ({ ...v }))
		store.fetchVersions = vi.fn().mockResolvedValue()
		store.viewVersion = vi.fn(async (id) => ({
			id,
			name: `name-${id}`,
			key: `key-${id}`,
		}))
		store.reset = vi.fn()
		useSecretStore().fetchSecret = vi
			.fn()
			.mockResolvedValue({ id: 'secret-1', name: 'now', key: 'key-now' })
	})

	async function mountPanel() {
		const wrapper = mount(VersionHistoryPanel, {
			props: { secretId: 'secret-1', canManage: true },
			global: { stubs, mixins: [substitutingT] },
		})
		await flush()
		return wrapper
	}

	it('unpicks an entry, which frees a place for another', async () => {
		const wrapper = await mountPanel()
		await wrapper.find('[data-testid="version-pick-3"]').setValue(true)
		await wrapper.find('[data-testid="version-pick-1"]').setValue(true)
		expect(
			wrapper.find('[data-testid="version-pick-2"]').attributes('disabled'),
		).toBeDefined()

		await wrapper.find('[data-testid="version-pick-3"]').setValue(false)

		expect(wrapper.vm.picked).toEqual(['v-1'])
		expect(
			wrapper.find('[data-testid="version-compare"]').attributes('disabled'),
		).toBeDefined()
		expect(
			wrapper.find('[data-testid="version-pick-2"]').attributes('disabled'),
		).toBeUndefined()
	})

	it('decrypts nothing when asked to compare with one pick', async () => {
		const wrapper = await mountPanel()
		await wrapper.find('[data-testid="version-pick-2"]').setValue(true)

		await wrapper.vm.onCompare()

		expect(store.viewVersion).not.toHaveBeenCalled()
		expect(useSecretStore().fetchSecret).not.toHaveBeenCalled()
		expect(wrapper.vm.comparison).toBe(null)
	})

	it('drops the decrypted states when the comparison is closed', async () => {
		const wrapper = await mountPanel()
		await wrapper.find('[data-testid="version-pick-3"]').setValue(true)
		await wrapper.find('[data-testid="version-pick-2"]').setValue(true)
		await wrapper.find('[data-testid="version-compare"]').trigger('click')
		await flush()
		expect(wrapper.vm.comparison).not.toBe(null)

		await wrapper.find('[data-testid="compare-close"]').trigger('click')

		expect(wrapper.vm.comparison).toBe(null)
		expect(wrapper.find('[data-testid="compare-stub"]').exists()).toBe(false)
	})
})
