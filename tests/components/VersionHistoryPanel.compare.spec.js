/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Comparing two entries from the version history panel: only the owner is
 * offered it, at most two entries are picked, both are decrypted through the
 * stores (nothing else is called), and the older one goes on the left.
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
			Object.entries(vars || {}).reduce((acc, [name, value]) => acc.replace(`{${name}}`, value), key),
	},
}

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { template: '<div><slot /></div>' },
	History: true,
	VersionDetailsDialog: true,
	VersionRestoreDialog: true,
	VersionCompareDialog: {
		props: ['comparison'],
		emits: ['close'],
		template: '<div v-if="comparison" data-testid="compare-stub" />',
	},
}

const VERSIONS = [
	{ id: 'v-3', versionNumber: 3, actorId: 'alice', createdAt: '2026-05-02T10:00:00Z' },
	{ id: 'v-2', versionNumber: 2, actorId: 'bob', createdAt: '2026-04-01T09:30:00Z' },
	{ id: 'v-1', versionNumber: 1, actorId: 'bob', createdAt: '2026-03-01T09:30:00Z' },
]

describe('VersionHistoryPanel compare', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSecretVersionStore()
		store.versions = VERSIONS.map((v) => ({ ...v }))
		store.fetchVersions = vi.fn().mockResolvedValue()
		store.viewVersion = vi.fn(async (id) => ({ id, name: `name-${id}`, key: `key-${id}` }))
		store.reset = vi.fn()
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({ id: 'secret-1', name: 'now', key: 'key-now' })
	})

	async function mountPanel(props = {}) {
		const wrapper = mount(VersionHistoryPanel, {
			props: { secretId: 'secret-1', ...props },
			global: { stubs, mixins: [substitutingT] },
		})
		await flush()
		return wrapper
	}

	it('offers no comparison to a viewer who does not own the secret', async () => {
		const wrapper = await mountPanel({ canManage: false })

		expect(wrapper.find('[data-testid="version-compare-bar"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="version-pick-2"]').exists()).toBe(false)
	})

	it('enables Compare only once two entries are picked, and allows no third', async () => {
		const wrapper = await mountPanel({ canManage: true })
		const compare = () => wrapper.find('[data-testid="version-compare"]')

		expect(compare().attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="version-pick-3"]').setValue(true)
		expect(compare().attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="version-pick-1"]').setValue(true)
		expect(compare().attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-testid="version-pick-2"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="version-pick-current"]').attributes('disabled')).toBeDefined()
	})

	it('compares two versions, the older on the left, decrypting both through the version store', async () => {
		const wrapper = await mountPanel({ canManage: true })
		await wrapper.find('[data-testid="version-pick-3"]').setValue(true)
		await wrapper.find('[data-testid="version-pick-1"]').setValue(true)
		await wrapper.find('[data-testid="version-compare"]').trigger('click')
		await flush()

		expect(store.viewVersion).toHaveBeenCalledTimes(2)
		expect(store.viewVersion).toHaveBeenCalledWith('v-1')
		expect(store.viewVersion).toHaveBeenCalledWith('v-3')
		expect(useSecretStore().fetchSecret).not.toHaveBeenCalled()
		expect(wrapper.vm.comparison).toEqual({
			left: { id: 'v-1', name: 'name-v-1', key: 'key-v-1' },
			right: { id: 'v-3', name: 'name-v-3', key: 'key-v-3' },
			leftLabel: 'Version 1',
			rightLabel: 'Version 3',
		})
		expect(wrapper.find('[data-testid="compare-stub"]').exists()).toBe(true)
	})

	it('compares a version with the current value, which goes on the right', async () => {
		const wrapper = await mountPanel({ canManage: true })
		await wrapper.find('[data-testid="version-pick-current"]').setValue(true)
		await wrapper.find('[data-testid="version-pick-2"]').setValue(true)
		await wrapper.find('[data-testid="version-compare"]').trigger('click')
		await flush()

		expect(useSecretStore().fetchSecret).toHaveBeenCalledWith('secret-1')
		expect(store.viewVersion).toHaveBeenCalledWith('v-2')
		expect(wrapper.vm.comparison.left).toEqual({ id: 'v-2', name: 'name-v-2', key: 'key-v-2' })
		expect(wrapper.vm.comparison.right).toEqual({ id: 'secret-1', name: 'now', key: 'key-now' })
		expect(wrapper.vm.comparison.rightLabel).toBe('Current value')
	})

	it('opens no comparison when decrypting a pick fails', async () => {
		store.viewVersion = vi.fn().mockRejectedValue(new Error('Vault is locked'))
		const wrapper = await mountPanel({ canManage: true })
		await wrapper.find('[data-testid="version-pick-3"]').setValue(true)
		await wrapper.find('[data-testid="version-pick-2"]').setValue(true)
		await wrapper.find('[data-testid="version-compare"]').trigger('click')
		await flush()

		expect(wrapper.vm.comparison).toBe(null)
		expect(wrapper.vm.comparing).toBe(false)
	})
})
