/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/OfflineConflictDialog.vue`: the server
 * version is decrypted in the browser (an undecryptable value shows blank,
 * never an error), a deleted offline copy is worded as a deletion, a failed
 * resolution keeps the dialog open with the reason, and closing is passed up.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OfflineConflictDialog from '../../src/dialogs/OfflineConflictDialog.vue'
import * as rsa from '../../src/crypto/rsa.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcDialog: {
		props: ['open'],
		emits: ['update:open'],
		template: '<div><button data-testid="dialog-close" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div data-testid="note" :class="`note--${type}`"><slot /></div>' },
}

async function mountDialog(entry, conflict) {
	const offline = useOfflineStore()
	offline.plaintextOf = vi.fn().mockResolvedValue({ key: 'mine-value' })
	offline.resolveConflict = vi.fn().mockResolvedValue()
	offline.conflicts = { e1: conflict }
	const wrapper = mount(OfflineConflictDialog, {
		props: { open: true, entry: { entryId: 'e1', op: 'update', body: { name: 'My name' }, ...entry } },
		global: { stubs },
	})
	await flush()
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('OfflineConflictDialog', () => {
	it('decrypts the server value with the session key', async () => {
		const session = useSessionStore()
		session.cryptoKey = { private: true }
		const decrypt = vi.spyOn(rsa, 'rsaDecrypt').mockResolvedValue('server-value')

		const wrapper = await mountDialog({}, { name: 'Server name', key: 'CIPHER' })

		expect(decrypt).toHaveBeenCalledWith('CIPHER', { private: true })
		expect(wrapper.vm.server).toEqual({ name: 'Server name', key: 'server-value' })
		expect(wrapper.find('[data-testid="offline-conflict-server"]').text()).toContain('server-value')
	})

	it('shows a blank server value when it cannot be decrypted', async () => {
		vi.spyOn(rsa, 'rsaDecrypt').mockRejectedValue(new Error('bad key'))

		const wrapper = await mountDialog({}, { name: 'Server name', key: 'CIPHER' })

		expect(wrapper.vm.server).toEqual({ name: 'Server name', key: '' })
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.find('[data-testid="note"]').exists()).toBe(false)
	})

	it('does not decrypt when the server version has no value', async () => {
		const decrypt = vi.spyOn(rsa, 'rsaDecrypt')

		const wrapper = await mountDialog({}, undefined)

		expect(decrypt).not.toHaveBeenCalled()
		expect(wrapper.vm.server).toEqual({ name: undefined, key: '' })
	})

	it('words an offline deletion as a deletion', async () => {
		const wrapper = await mountDialog({ op: 'delete' }, { name: 'Server name' })

		expect(wrapper.text()).toContain('You deleted this secret offline')
		expect(wrapper.find('[data-testid="offline-conflict-mine"]').text()).toContain('Deleted')
		expect(wrapper.find('[data-testid="offline-conflict-mine"]').text()).not.toContain('mine-value')
	})

	it('falls back to the server name when the offline change kept it', async () => {
		const wrapper = await mountDialog({ body: {} }, { name: 'Server name' })

		expect(wrapper.find('[data-testid="offline-conflict-mine"]').text()).toContain('Server name')
	})

	it('keeps the dialog open with the reason when resolving fails', async () => {
		const wrapper = await mountDialog({}, { name: 'Server name' })
		useOfflineStore().resolveConflict.mockRejectedValueOnce(new Error('server unreachable'))

		await wrapper.find('[data-testid="offline-conflict-keep-mine"]').trigger('click')
		await flush()

		expect(wrapper.vm.error).toBe('server unreachable')
		expect(wrapper.find('[data-testid="note"]').text()).toBe('server unreachable')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.emitted('resolved')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('reports a non-error rejection as text', async () => {
		const wrapper = await mountDialog({}, { name: 'Server name' })
		useOfflineStore().resolveConflict.mockRejectedValueOnce('offline')

		await wrapper.vm.choose('server')

		expect(wrapper.vm.error).toBe('offline')
	})

	it('emits the choice and closes once resolved', async () => {
		const wrapper = await mountDialog({}, { name: 'Server name' })

		await wrapper.vm.choose('server')

		expect(wrapper.emitted('resolved')).toEqual([['server']])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('passes closing up', async () => {
		const wrapper = await mountDialog({}, { name: 'Server name' })

		await wrapper.find('[data-testid="dialog-close"]').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(useOfflineStore().resolveConflict).not.toHaveBeenCalled()
	})
})
