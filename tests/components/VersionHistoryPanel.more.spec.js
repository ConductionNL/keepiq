/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The version-history panel on the secret detail: it loads the secret's
 * versions on mount and resets the store on unmount, lists each version with
 * its number, actor and time, opens a decrypted version read-only, and
 * restores only through the confirmation dialog, emitting `restored` when
 * the restore succeeded. Restore is offered to the owner (`canManage`) only.
 * The store's decrypting actions are replaced; the two dialogs are stubbed to
 * their props and events. (`localDateTime.spec.js` covers the date format.)
 *
 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VersionHistoryPanel from '../../src/components/VersionHistoryPanel.vue'
import { useSecretVersionStore } from '../../src/store/modules/secretVersion.js'
import { localDateTime } from '../../src/utils/dateTime.js'

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
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note-card" :data-type="type"><slot /></div>',
	},
	History: true,
	VersionDetailsDialog: {
		props: ['version'],
		emits: ['close'],
		template:
			'<div v-if="version" data-testid="details-stub">{{ version.name }}<button data-testid="details-close" @click="$emit(\'close\')" /></div>',
	},
	VersionRestoreDialog: {
		props: ['version'],
		emits: ['close', 'confirm'],
		template:
			'<div v-if="version" data-testid="restore-stub">{{ version.versionNumber }}<button data-testid="restore-confirm" @click="$emit(\'confirm\')" /><button data-testid="restore-cancel" @click="$emit(\'close\')" /></div>',
	},
}

const VERSIONS = [
	{ id: 'v-3', versionNumber: 3, actorId: 'alice', createdAt: '2026-05-02T10:00:00Z' },
	{ id: 'v-2', versionNumber: 2, actorId: 'bob', createdAt: '2026-04-01T09:30:00Z' },
]

describe('VersionHistoryPanel', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSecretVersionStore()
		store.versions = VERSIONS.map((v) => ({ ...v }))
		store.fetchVersions = vi.fn().mockResolvedValue()
		store.viewVersion = vi.fn().mockResolvedValue({ id: 'v-2', name: 'Old name', key: 'old' })
		store.restore = vi.fn().mockResolvedValue()
		store.reset = vi.fn()
	})

	/**
	 * Mount the panel and let `mounted` settle.
	 *
	 * @param {object} props Extra props.
	 * @return {Promise<object>}
	 */
	async function mountPanel(props = {}) {
		const wrapper = mount(VersionHistoryPanel, {
			props: { secretId: 'secret-1', ...props },
			global: { stubs, mixins: [substitutingT] },
		})
		await flush()
		return wrapper
	}

	it('loads the versions of its secret and lists number, actor and time', async () => {
		const wrapper = await mountPanel()

		expect(store.fetchVersions).toHaveBeenCalledWith('secret-1')
		const label = wrapper.find('[data-testid="version-3"]')
		expect(label.text()).toContain('Version 3')
		expect(label.text()).toContain('alice')
		expect(label.text()).toContain(localDateTime('2026-05-02T10:00:00Z'))
		expect(wrapper.find('[data-testid="version-2"]').text()).toContain('bob')
		expect(wrapper.find('.version-history__empty').exists()).toBe(false)
	})

	it('says there are no previous versions when the list is empty', async () => {
		store.versions = []
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="version-list"]').exists()).toBe(false)
		expect(wrapper.find('.version-history__empty').text()).toBe(
			'No previous versions',
		)
	})

	it('survives a failed load and shows the store error', async () => {
		store.fetchVersions = vi.fn(async () => {
			store.error = 'Could not load versions'
			throw new Error('boom')
		})
		const wrapper = await mountPanel()

		const note = wrapper.find('[data-testid="version-error"]')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Could not load versions')
	})

	it('offers restore to the owner only', async () => {
		const viewer = await mountPanel()
		expect(viewer.find('[data-testid="version-restore-3"]').exists()).toBe(false)
		expect(viewer.find('[data-testid="version-view-3"]').exists()).toBe(true)

		const owner = await mountPanel({ canManage: true })
		expect(owner.find('[data-testid="version-restore-3"]').exists()).toBe(true)
	})

	it('opens the decrypted version read-only and closes it again', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="details-stub"]').exists()).toBe(false)
		await wrapper.find('[data-testid="version-view-2"]').trigger('click')
		await flush()

		expect(store.viewVersion).toHaveBeenCalledWith('v-2')
		expect(wrapper.find('[data-testid="details-stub"]').text()).toContain(
			'Old name',
		)

		await wrapper.find('[data-testid="details-close"]').trigger('click')
		expect(wrapper.find('[data-testid="details-stub"]').exists()).toBe(false)
	})

	it('opens nothing when the version cannot be decrypted', async () => {
		store.viewVersion = vi.fn().mockRejectedValue(new Error('no key'))
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="version-view-3"]').trigger('click')
		await flush()

		expect(store.viewVersion).toHaveBeenCalledWith('v-3')
		expect(wrapper.find('[data-testid="details-stub"]').exists()).toBe(false)
	})

	it('asks before restoring and does not restore on the first click', async () => {
		const wrapper = await mountPanel({ canManage: true })

		await wrapper.find('[data-testid="version-restore-2"]').trigger('click')

		expect(wrapper.find('[data-testid="restore-stub"]').text()).toContain('2')
		expect(store.restore).not.toHaveBeenCalled()
	})

	it('restores the confirmed version of this secret and emits restored', async () => {
		const wrapper = await mountPanel({ canManage: true })

		await wrapper.find('[data-testid="version-restore-2"]').trigger('click')
		await wrapper.find('[data-testid="restore-confirm"]').trigger('click')
		await flush()

		expect(store.restore).toHaveBeenCalledTimes(1)
		expect(store.restore).toHaveBeenCalledWith('v-2', 'secret-1')
		expect(wrapper.emitted('restored')).toHaveLength(1)
		expect(wrapper.find('[data-testid="restore-stub"]').exists()).toBe(false)
	})

	it('cancelling the confirmation restores nothing', async () => {
		const wrapper = await mountPanel({ canManage: true })

		await wrapper.find('[data-testid="version-restore-3"]').trigger('click')
		await wrapper.find('[data-testid="restore-cancel"]').trigger('click')
		await flush()

		expect(store.restore).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="restore-stub"]').exists()).toBe(false)
		expect(wrapper.emitted('restored')).toBeUndefined()
	})

	it('does not emit restored when the restore fails', async () => {
		store.restore = vi.fn().mockRejectedValue(new Error('conflict'))
		const wrapper = await mountPanel({ canManage: true })

		await wrapper.find('[data-testid="version-restore-3"]').trigger('click')
		await wrapper.find('[data-testid="restore-confirm"]').trigger('click')
		await flush()

		expect(store.restore).toHaveBeenCalledWith('v-3', 'secret-1')
		expect(wrapper.emitted('restored')).toBeUndefined()
		expect(wrapper.find('[data-testid="restore-stub"]').exists()).toBe(false)
	})

	it('a confirm with no version pending restores nothing', async () => {
		const wrapper = await mountPanel({ canManage: true })

		await wrapper.vm.onRestore()

		expect(store.restore).not.toHaveBeenCalled()
		expect(wrapper.emitted('restored')).toBeUndefined()
	})

	it('resets the store when it unmounts', async () => {
		const wrapper = await mountPanel()
		expect(store.reset).not.toHaveBeenCalled()

		wrapper.unmount()

		expect(store.reset).toHaveBeenCalledTimes(1)
	})
})
