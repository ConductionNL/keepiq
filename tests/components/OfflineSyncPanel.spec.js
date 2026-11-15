/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/OfflineSyncPanel.vue`, beyond the
 * pending-count / failed-list paths pinned in OfflineEdits.spec.js.
 *
 * Covered: the panel renders nothing for an empty queue; a conflict entry is
 * listed (with the "Secret" fallback name) and "Choose a version" opens the
 * conflict dialog for that entry, which closes again on `update:open(false)`;
 * the reopen form for changes sealed under rotated keys (submit disabled
 * until a password is typed, a successful reopen calls the store with that
 * password, replays the queue and clears the field, a refused password shows
 * the error and replays nothing), discarding those changes; and copying a
 * refused change whose plaintext has no value writes an empty string and
 * confirms.
 *
 * The offline store is real; its crypto/network actions
 * (`reopenWithPreviousPassword`, `replayQueue`, `discardForeignEntries`,
 * `plaintextOf`) are replaced with mocks, as is `navigator.clipboard`.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OfflineSyncPanel from '../../src/components/OfflineSyncPanel.vue'
import { useOfflineStore } from '../../src/store/modules/offline.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcButton: {
		props: ['disabled', 'type', 'variant'],
		emits: ['click'],
		template:
			'<button :type="type || \'button\'" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input class="pw" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	OfflineConflictDialog: {
		name: 'OfflineConflictDialog',
		props: ['open', 'entry'],
		emits: ['update:open'],
		template: '<div data-testid="conflict-dialog-stub" />',
	},
}

/**
 * Mount the panel with the shared stubs.
 *
 * @return {object} The wrapper.
 */
function mountPanel() {
	return mount(OfflineSyncPanel, { global: { stubs } })
}

describe('OfflineSyncPanel', () => {
	let offline

	beforeEach(() => {
		setActivePinia(createPinia())
		offline = useOfflineStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders nothing when the queue is empty', () => {
		const wrapper = mountPanel()
		expect(wrapper.find('[data-testid="offline-sync-panel"]').exists()).toBe(false)
	})

	it('lists a conflict and opens / closes the conflict dialog for it', async () => {
		offline.entries = [
			{ entryId: 'c1', op: 'update', secretId: 's1', status: 'conflict', body: {} },
		]
		const wrapper = mountPanel()

		expect(wrapper.find('[data-testid="offline-sync-pending"]').text()).toBe(
			'1 change waiting to sync',
		)
		expect(wrapper.find('.keepiq-offline-sync__list li').text()).toContain('Secret')
		expect(wrapper.findComponent({ name: 'OfflineConflictDialog' }).exists()).toBe(false)

		await wrapper.find('[data-testid="offline-sync-resolve-c1"]').trigger('click')
		const dialog = wrapper.findComponent({ name: 'OfflineConflictDialog' })
		expect(dialog.props('entry')).toEqual(offline.entries[0])
		expect(dialog.props('open')).toBe(true)

		// Re-opening (true) keeps it; closing (false) drops the entry.
		dialog.vm.$emit('update:open', true)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.conflictEntry).not.toBeNull()
		dialog.vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.conflictEntry).toBeNull()
		expect(wrapper.findComponent({ name: 'OfflineConflictDialog' }).exists()).toBe(false)
	})

	describe('changes made under rotated keys', () => {
		beforeEach(() => {
			offline.foreignEntries = [{ entryId: 'x1' }]
			offline.reopenWithPreviousPassword = vi.fn().mockResolvedValue(1)
			offline.replayQueue = vi.fn().mockResolvedValue()
			offline.discardForeignEntries = vi.fn().mockResolvedValue()
		})

		it('enables submit once a password is typed, then reopens and replays', async () => {
			const wrapper = mountPanel()
			const form = wrapper.find('[data-testid="offline-sync-reopen"]')
			const submit = form.find('button[type="submit"]')
			expect(submit.attributes('disabled')).toBeDefined()

			await form.find('input.pw').setValue('pw')
			expect(submit.attributes('disabled')).toBeUndefined()

			await form.trigger('submit')
			await flush()

			expect(offline.reopenWithPreviousPassword).toHaveBeenCalledExactlyOnceWith('pw')
			expect(offline.replayQueue).toHaveBeenCalledOnce()
			expect(wrapper.vm.previousPassword).toBe('')
			expect(wrapper.find('.keepiq-offline-sync__message').exists()).toBe(false)
		})

		it('explains a refused password and does not replay', async () => {
			offline.reopenWithPreviousPassword = vi.fn().mockRejectedValue(new Error('bad key'))
			const wrapper = mountPanel()

			await wrapper.find('input.pw').setValue('wrong')
			await wrapper.find('[data-testid="offline-sync-reopen"]').trigger('submit')
			await flush()

			expect(offline.reopenWithPreviousPassword).toHaveBeenCalledWith('wrong')
			expect(offline.replayQueue).not.toHaveBeenCalled()
			expect(wrapper.vm.previousPassword).toBe('wrong')
			expect(wrapper.find('.keepiq-offline-sync__message').text()).toBe(
				'That password did not open your changes.',
			)
		})

		it('discards the changes on request', async () => {
			const wrapper = mountPanel()
			const buttons = wrapper.find('[data-testid="offline-sync-reopen"]').findAll('button')
			const discard = buttons.find((b) => b.text() === 'Discard')

			await discard.trigger('click')

			expect(offline.discardForeignEntries).toHaveBeenCalledOnce()
			expect(offline.reopenWithPreviousPassword).not.toHaveBeenCalled()
		})
	})

	it('copies an empty string when a refused change has no value, and confirms', async () => {
		offline.entries = [
			{ entryId: 'f1', op: 'update', secretId: 's1', status: 'failed' },
		]
		offline.plaintextOf = vi.fn().mockResolvedValue({})
		const writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
		const wrapper = mountPanel()

		await wrapper.find('[data-testid="offline-sync-copy-f1"]').trigger('click')
		await flush()

		// A body-less entry is opened as an empty body.
		expect(offline.plaintextOf).toHaveBeenCalledWith({})
		expect(writeText).toHaveBeenCalledExactlyOnceWith('')
		expect(wrapper.find('.keepiq-offline-sync__message').text()).toBe('Copied!')
		// No pending count: only failed entries.
		expect(wrapper.find('[data-testid="offline-sync-pending"]').exists()).toBe(false)
	})
})
