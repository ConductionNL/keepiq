/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The offline edit UI (keepiq#785): which actions the secret sidebar offers
 * offline with and without offline edits, the sync panel (pending count,
 * failed list with copy and discard), the conflict dialog's two choices, the
 * unload warning while changes are pending, and the admin switch.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OfflineSyncPanel from '../../src/components/OfflineSyncPanel.vue'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import OfflineCacheSection from '../../src/components/settings/OfflineCacheSection.vue'
import OfflineConflictDialog from '../../src/dialogs/OfflineConflictDialog.vue'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const button = {
	props: ['disabled'],
	emits: ['click'],
	template:
		'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}
const sidebarStubs = {
	NcAppSidebar: { template: '<aside><slot name="description" /><slot /></aside>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	NcButton: button,
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: true,
	GroupShareList: true,
	DelegationManager: true,
	ShareRequestForm: true,
	SecretRequestList: true,
	SecretRequestCreateDialog: true,
	AttachmentPanel: {
		props: ['canManage'],
		template:
			'<div data-testid="attachments" :data-manage="String(canManage)" />',
	},
}

async function mountSidebar({ editsEnabled }) {
	window.OC = { currentUser: 'alice' }
	const offline = useOfflineStore()
	offline.servedFromCache = true
	offline.editsEnabled = editsEnabled
	useSecretStore().fetchSecret = vi
		.fn()
		.mockResolvedValue({ id: 's-1', name: 'Router', key: 'C', ownerId: 'alice' })
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: { stubs: sidebarStubs },
	})
	await flush()
	await flush()
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
})

describe('secret actions offline', () => {
	it('offers edit, move and delete with offline edits on, and keeps share and attachments online-only', async () => {
		const wrapper = await mountSidebar({ editsEnabled: true })
		expect(wrapper.find('[data-testid="secret-detail-edit"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="secret-detail-move"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="secret-detail-delete"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="secret-detail-archive"]').exists()).toBe(
			false,
		)
		expect(
			wrapper
				.find('[data-testid="secret-detail-share"]')
				.attributes('disabled'),
		).toBeDefined()
		expect(
			wrapper.find('[data-testid="attachments"]').attributes('data-manage'),
		).toBe('false')
		expect(
			wrapper.find('[data-testid="secret-detail-offline-note"]').text(),
		).toContain('Sharing and attachments need a connection')
	})

	it('offers no write action with offline edits off, and explains why', async () => {
		const wrapper = await mountSidebar({ editsEnabled: false })
		expect(wrapper.find('[data-testid="secret-detail-edit"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="secret-detail-move"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="secret-detail-delete"]').exists()).toBe(
			false,
		)
		expect(
			wrapper
				.find('[data-testid="secret-detail-share"]')
				.attributes('disabled'),
		).toBeDefined()
		expect(
			wrapper.find('[data-testid="secret-detail-offline-note"]').text(),
		).toContain('Read-only while offline')
	})
})

describe('the sync panel', () => {
	it('shows the pending count and lets the user copy or discard a refused change', async () => {
		const offline = useOfflineStore()
		offline.entries = [
			{
				entryId: 'e1',
				op: 'update',
				secretId: 's1',
				status: 'queued',
				body: { name: 'A' },
			},
			{
				entryId: 'e2',
				op: 'update',
				secretId: 's2',
				status: 'failed',
				body: { name: 'Router', key: 'C' },
			},
		]
		offline.plaintextOf = vi.fn().mockResolvedValue({ key: 'secret-value' })
		offline.discardEntry = vi.fn()
		const writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})

		const wrapper = mount(OfflineSyncPanel, {
			global: {
				stubs: {
					NcButton: button,
					NcPasswordField: true,
					OfflineConflictDialog: true,
				},
			},
		})

		expect(wrapper.find('[data-testid="offline-sync-pending"]').text()).toBe(
			'1 change waiting to sync',
		)
		expect(wrapper.find('[data-testid="offline-sync-failed"]').text()).toContain(
			'Router',
		)

		await wrapper.find('[data-testid="offline-sync-copy-e2"]').trigger('click')
		await flush()
		expect(writeText).toHaveBeenCalledWith('secret-value')

		await wrapper
			.find('[data-testid="offline-sync-discard-e2"]')
			.trigger('click')
		expect(offline.discardEntry).toHaveBeenCalledWith('e2')
	})
})

describe('the conflict dialog', () => {
	it.each([
		['offline-conflict-keep-mine', 'mine'],
		['offline-conflict-keep-server', 'server'],
	])('%s applies the choice', async (testid, choice) => {
		const offline = useOfflineStore()
		offline.plaintextOf = vi.fn().mockResolvedValue({ key: 'mine-value' })
		offline.resolveConflict = vi.fn().mockResolvedValue()
		offline.conflicts = { e1: { name: 'Server name' } }
		const wrapper = mount(OfflineConflictDialog, {
			props: {
				open: true,
				entry: { entryId: 'e1', op: 'update', body: { name: 'My name' } },
			},
			global: {
				stubs: {
					NcDialog: {
						template: '<div><slot /><slot name="actions" /></div>',
					},
					NcButton: button,
					NcNoteCard: true,
				},
			},
		})
		await flush()
		expect(
			wrapper.find('[data-testid="offline-conflict-mine"]').text(),
		).toContain('mine-value')
		expect(
			wrapper.find('[data-testid="offline-conflict-server"]').text(),
		).toContain('Server name')

		await wrapper.find(`[data-testid="${testid}"]`).trigger('click')
		await flush()
		expect(offline.resolveConflict).toHaveBeenCalledWith('e1', choice)
	})
})

describe('leaving with pending changes', () => {
	it('asks the browser to confirm only while changes are pending', async () => {
		const App = (await import('../../src/App.vue')).default
		const offline = useOfflineStore()
		const ctx = {
			offlineStore: offline,
			sessionStore: { lock: vi.fn() },
			unloading: false,
		}
		const event = { preventDefault: vi.fn(), returnValue: undefined }

		App.methods.handleBeforeUnload.call(ctx, event)
		expect(event.preventDefault).not.toHaveBeenCalled()

		offline.entries = [{ entryId: 'e1', status: 'queued', body: {} }]
		App.methods.handleBeforeUnload.call(ctx, event)
		expect(event.preventDefault).toHaveBeenCalled()
	})
})

describe('the admin switch', () => {
	it('loads and saves offline edits', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { offline_cache_enabled: true, offline_edits_enabled: false },
		})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(OfflineCacheSection, {
			global: {
				stubs: {
					CnSettingsSection: { template: '<section><slot /></section>' },
				},
			},
		})
		await flush()
		const box = wrapper.find('[data-testid="offline-edits-enabled"]')
		expect(box.element.checked).toBe(false)
		await box.setValue(true)
		await flush()
		expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/admin', {
			offline_edits_enabled: true,
		})
	})
})
