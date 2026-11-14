/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Preview in the attachments box (vault-item-clone-preview-and-print, task
 * 2.2): offered for images, PDFs and plain text only, shown in a modal, and
 * the object URL is revoked when the modal closes. Nothing is downloaded.
 *
 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentPanel from '../../src/components/AttachmentPanel.vue'
import AttachmentPreviewModal from '../../src/modals/AttachmentPreviewModal.vue'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="dialog-stub" :data-name="name"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

const ROWS = [
	{ id: 'png', filename: 'recovery.png', contentType: 'image/png', sizeBytes: 10, wrappedFileKey: 'w' },
	{ id: 'svg', filename: 'logo.svg', contentType: 'image/svg+xml', sizeBytes: 10, wrappedFileKey: 'w' },
	{ id: 'txt', filename: 'codes.txt', contentType: 'text/plain', sizeBytes: 10, wrappedFileKey: 'w' },
	{ id: 'zip', filename: 'backup.zip', contentType: 'application/zip', sizeBytes: 10, wrappedFileKey: 'w' },
]

let revokeObjectURL

async function mountPanel() {
	const store = useAttachmentStore()
	store.fetchAttachments = vi.fn(async () => {
		store.attachments = ROWS
	})
	store.download = vi.fn()
	const wrapper = mount(AttachmentPanel, { props: { secretId: 'sec-1' }, global: { stubs } })
	await flush()
	return { wrapper, store }
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	revokeObjectURL = vi.fn()
	vi.stubGlobal('URL', Object.assign(Object.create(URL), { createObjectURL: vi.fn(), revokeObjectURL }))
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('AttachmentPanel preview', () => {
	it('offers Preview for images and text only; SVG and other types keep Download only', async () => {
		const { wrapper } = await mountPanel()
		expect(wrapper.find('[data-testid="attachment-preview-png"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="attachment-preview-txt"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="attachment-preview-svg"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="attachment-preview-zip"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="attachment-download-svg"]').exists()).toBe(true)
	})

	it('shows a PNG in a modal, downloads nothing, and revokes the URL on close', async () => {
		const { wrapper, store } = await mountPanel()
		store.preview = vi.fn().mockResolvedValue({ kind: 'image', url: 'blob:keepiq/1', text: null })

		await wrapper.find('[data-testid="attachment-preview-png"]').trigger('click')
		await flush()

		expect(store.preview).toHaveBeenCalledWith(ROWS[0])
		expect(store.download).not.toHaveBeenCalled()
		const img = wrapper.find('[data-testid="attachment-preview-image"]')
		expect(img.attributes('src')).toBe('blob:keepiq/1')
		expect(wrapper.find('.dialog-stub').attributes('data-name')).toBe('recovery.png')

		await wrapper.find('[data-testid="attachment-preview-close"]').trigger('click')
		await flush()

		expect(revokeObjectURL).toHaveBeenCalledTimes(1)
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:keepiq/1')
		expect(wrapper.find('[data-testid="attachment-preview"]').exists()).toBe(false)
	})

	it('shows plain text in a pre, and leaves the panel closed when the decrypt fails', async () => {
		const { wrapper, store } = await mountPanel()
		store.preview = vi.fn().mockResolvedValue({ kind: 'text', url: null, text: '1234-5678' })
		await wrapper.find('[data-testid="attachment-preview-txt"]').trigger('click')
		await flush()
		expect(wrapper.find('[data-testid="attachment-preview-text"]').text()).toBe('1234-5678')

		await wrapper.find('[data-testid="attachment-preview-close"]').trigger('click')
		await flush()
		expect(revokeObjectURL).not.toHaveBeenCalled()

		store.preview = vi.fn().mockRejectedValue(new Error('bad key'))
		await wrapper.find('[data-testid="attachment-preview-png"]').trigger('click')
		await flush()
		expect(wrapper.find('[data-testid="attachment-preview"]').exists()).toBe(false)
	})
})

describe('AttachmentPreviewModal', () => {
	it('renders a PDF in a fully sandboxed frame and revokes its URL once when unmounted', () => {
		const wrapper = mount(AttachmentPreviewModal, {
			props: { preview: { kind: 'pdf', url: 'blob:keepiq/9', text: null }, filename: 'scan.pdf' },
			global: { stubs },
		})
		const frame = wrapper.find('[data-testid="attachment-preview-pdf"]')
		expect(frame.attributes('sandbox')).toBe('')
		expect(frame.attributes('src')).toBe('blob:keepiq/9')

		wrapper.vm.onUpdateOpen(false)
		expect(wrapper.emitted('close')).toHaveLength(1)
		wrapper.unmount()
		expect(revokeObjectURL).toHaveBeenCalledTimes(1)
	})
})
