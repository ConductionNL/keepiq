/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/AttachmentPanel.vue`, beyond the
 * refetch-on-`secretId` paths pinned in AttachmentPanel.spec.js.
 *
 * The attachment store is real; its network/crypto actions
 * (`fetchAttachments`, `upload`, `download`, `remove`) are replaced with
 * mocks. Covered: the empty line and the error note; each row's decrypted
 * name (or the undecryptable fallback) and human size in B / KB / MB (blank
 * when unknown); download for every viewer, delete and upload only for a
 * manager, with the exact store arguments; the upload button opening the
 * file picker and being disabled while the store is busy; a picked file
 * being uploaded and the input cleared even when the upload fails; an empty
 * pick uploading nothing; a failed mount fetch not breaking the panel; and
 * unmount resetting the store.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentPanel from '../../src/components/AttachmentPanel.vue'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const ROWS = [
	{ id: 'a1', filename: 'notes.txt', sizeBytes: 512 },
	{ id: 'a2', filename: null, sizeBytes: 2048 },
	{ id: 'a3', filename: 'scan.pdf', sizeBytes: 3 * 1048576 + 104858 },
	{ id: 'a4', filename: 'empty.bin', sizeBytes: null },
]

describe('AttachmentPanel rows and actions', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useAttachmentStore()
		store.fetchAttachments = vi.fn(async () => {
			store.attachments = ROWS.map((r) => ({ ...r }))
		})
		store.upload = vi.fn().mockResolvedValue()
		store.download = vi.fn().mockResolvedValue()
		store.remove = vi.fn().mockResolvedValue()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	/**
	 * Mount the panel and let the mount fetch settle.
	 *
	 * @param {object} props Extra props.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountPanel(props = {}) {
		const wrapper = mount(AttachmentPanel, {
			props: { secretId: 'sec-1', ...props },
		})
		await flush()
		return wrapper
	}

	it('shows the empty line when there are no attachments', async () => {
		store.fetchAttachments = vi.fn().mockResolvedValue()
		const wrapper = await mountPanel()

		expect(store.fetchAttachments).toHaveBeenCalledExactlyOnceWith('sec-1')
		expect(wrapper.find('[data-testid="attachment-list"]').exists()).toBe(false)
		expect(wrapper.find('.attachment-panel__empty').text()).toBe('No attachments')
	})

	it('shows the store error in a note', async () => {
		store.fetchAttachments = vi.fn(async () => {
			store.error = 'Could not load attachments'
		})
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="attachment-error"]').text()).toBe(
			'Could not load attachments',
		)
	})

	it('keeps rendering when the mount fetch rejects', async () => {
		store.fetchAttachments = vi.fn().mockRejectedValue(new Error('offline'))
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="attachment-panel"]').exists()).toBe(true)
		expect(wrapper.find('.attachment-panel__empty').exists()).toBe(true)
	})

	it('renders names with fallback and human sizes', async () => {
		const wrapper = await mountPanel()

		const rows = wrapper.findAll('.attachment-panel__row').map((row) => [
			row.find('.attachment-panel__name').text(),
			row.find('.attachment-panel__size').text(),
		])
		expect(rows).toEqual([
			['notes.txt', '512 B'],
			['(undecryptable attachment)', '2.0 KB'],
			['scan.pdf', '3.1 MB'],
			['empty.bin', ''],
		])
	})

	it('lets a viewer download but not delete or upload', async () => {
		const wrapper = await mountPanel({ canManage: false })

		expect(wrapper.find('[data-testid="attachment-delete-a1"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="attachment-file-input"]').exists()).toBe(false)

		await wrapper.find('[data-testid="attachment-download-a3"]').trigger('click')
		expect(store.download).toHaveBeenCalledOnce()
		expect(store.download.mock.calls[0][0]).toEqual(ROWS[2])
	})

	it('lets a manager delete an attachment of this secret', async () => {
		const wrapper = await mountPanel({ canManage: true })

		await wrapper.find('[data-testid="attachment-delete-a2"]').trigger('click')

		expect(store.remove).toHaveBeenCalledExactlyOnceWith('sec-1', 'a2')
	})

	it('opens the file picker from the upload button, disabled while busy', async () => {
		const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
		const wrapper = await mountPanel({ canManage: true })
		const button = wrapper.find('[data-testid="attachment-upload"]')

		expect(button.attributes('disabled')).toBeUndefined()
		await button.trigger('click')
		expect(click).toHaveBeenCalledOnce()
		expect(click.mock.contexts[0]).toBe(
			wrapper.find('[data-testid="attachment-file-input"]').element,
		)

		store.loading = true
		await wrapper.vm.$nextTick()
		expect(button.attributes('disabled')).toBeDefined()
	})

	it('uploads the picked file to this secret', async () => {
		const wrapper = await mountPanel({ canManage: true })
		const file = new File(['abc'], 'pic.png', { type: 'image/png' })
		const input = wrapper.find('[data-testid="attachment-file-input"]')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })

		await input.trigger('change')
		await flush()

		expect(store.upload).toHaveBeenCalledExactlyOnceWith('sec-1', file)
	})

	it('clears the input even when the upload fails', async () => {
		store.upload = vi.fn().mockRejectedValue(new Error('too large'))
		const wrapper = await mountPanel({ canManage: true })
		const file = new File(['abc'], 'big.bin')
		const target = { files: [file], value: 'big.bin' }

		await expect(wrapper.vm.onFilePicked({ target })).rejects.toThrow('too large')

		expect(store.upload).toHaveBeenCalledWith('sec-1', file)
		expect(target.value).toBe('')
	})

	it('uploads nothing when the pick is empty', async () => {
		const wrapper = await mountPanel({ canManage: true })
		const target = { files: [], value: '' }

		await wrapper.vm.onFilePicked({ target })
		await wrapper.vm.onFilePicked({ target: { files: null } })

		expect(store.upload).not.toHaveBeenCalled()
	})

	it('resets the store on unmount', async () => {
		const wrapper = await mountPanel()
		expect(store.attachments).toHaveLength(4)
		const before = store.listRequestId

		wrapper.unmount()

		expect(store.attachments).toEqual([])
		expect(store.listRequestId).toBe(before + 1)
	})
})
