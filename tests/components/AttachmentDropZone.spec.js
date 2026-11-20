/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/AttachmentDropZone.vue`: files wait
 * in the list until `uploadPending()`, a refused file stays with its
 * reason, and a retry uploads only what is still listed.
 *
 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
 */

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentDropZone from '../../src/components/AttachmentDropZone.vue'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'

const stubs = {
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel'],
		template:
			'<button :disabled="disabled" :aria-label="ariaLabel" @click="$emit(\'click\')"><slot /></button>',
	},
}

/**
 * @param {string} name The file name.
 * @return {File}
 */
function file(name) {
	return new File(['x'], name, { type: 'text/plain' })
}

/**
 * Feed files to the hidden input as if the user picked them.
 *
 * @param {object} wrapper The mounted drop zone.
 * @param {Array<File>} files The picked files.
 * @return {Promise<void>}
 */
async function pick(wrapper, files) {
	const input = wrapper.find('[data-testid="attachment-dropzone-input"]')
	Object.defineProperty(input.element, 'files', {
		value: files,
		configurable: true,
	})
	await input.trigger('change')
}

enableAutoUnmount(afterEach)

describe('AttachmentDropZone', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useAttachmentStore()
		store.uploadFile = vi.fn().mockResolvedValue()
	})

	/**
	 * @return {object} The mounted drop zone.
	 */
	function mounted() {
		return mount(AttachmentDropZone, { global: { stubs } })
	}

	it('lists picked files and uploads nothing yet', async () => {
		const wrapper = mounted()
		await pick(wrapper, [file('a.txt'), file('b.txt')])

		expect(wrapper.text()).toContain('a.txt')
		expect(wrapper.text()).toContain('b.txt')
		expect(store.uploadFile).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:pending').at(-1)).toEqual([2])
	})

	it('lists dropped files and ignores a drop without files', async () => {
		const wrapper = mounted()
		const zone = wrapper.find('[data-testid="attachment-dropzone"]')

		await zone.trigger('drop', { dataTransfer: { files: [] } })
		expect(wrapper.find('[data-testid="attachment-pending-0"]').exists()).toBe(
			false,
		)

		await zone.trigger('drop', { dataTransfer: { files: [file('c.png')] } })
		expect(wrapper.find('[data-testid="attachment-pending-0"]').text()).toContain(
			'c.png',
		)
	})

	it('removes a waiting file', async () => {
		const wrapper = mounted()
		await pick(wrapper, [file('a.txt'), file('b.txt')])

		await wrapper.find('[data-testid="attachment-pending-remove-0"]').trigger('click')

		expect(wrapper.text()).not.toContain('a.txt')
		expect(wrapper.text()).toContain('b.txt')
		expect(wrapper.emitted('update:pending').at(-1)).toEqual([1])
	})

	it('keeps a refused file with its reason and retries only that one', async () => {
		store.uploadFile = vi
			.fn()
			.mockResolvedValueOnce()
			.mockRejectedValueOnce(new Error('Attachment quota exceeded'))
			.mockResolvedValueOnce()
		const wrapper = mounted()
		await pick(wrapper, [file('ok.txt'), file('big.txt')])

		const first = await wrapper.vm.uploadPending('s1')
		await flushPromises()

		expect(first).toBe(false)
		expect(store.uploadFile).toHaveBeenCalledTimes(2)
		expect(store.uploadFile.mock.calls[0][0]).toBe('s1')
		expect(wrapper.text()).not.toContain('ok.txt')
		expect(
			wrapper.find('[data-testid="attachment-pending-error-0"]').text(),
		).toContain('Attachment quota exceeded')

		const second = await wrapper.vm.uploadPending('s1')
		await flushPromises()

		expect(second).toBe(true)
		expect(store.uploadFile).toHaveBeenCalledTimes(3)
		expect(store.uploadFile.mock.calls[2][1].name).toBe('big.txt')
		expect(wrapper.find('[data-testid="attachment-pending-0"]').exists()).toBe(
			false,
		)
		expect(wrapper.emitted('update:pending').at(-1)).toEqual([0])
	})

	it('does not upload a file twice while its own Retry is still running', async () => {
		let finish
		store.uploadFile = vi
			.fn()
			.mockRejectedValueOnce(new Error('Attachment quota exceeded'))
			.mockImplementationOnce(() => new Promise((resolve) => {
				finish = resolve
			}))
		const wrapper = mount(AttachmentDropZone, {
			props: { secretId: 's1' },
			global: { stubs },
		})
		await pick(wrapper, [file('big.zip')])
		await wrapper.vm.uploadPending('s1')
		await flushPromises()

		await wrapper.find('[data-testid="attachment-pending-retry-0"]').trigger('click')
		const second = wrapper.vm.uploadPending('s1')
		await flushPromises()

		expect(store.uploadFile).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('update:busy').at(-1)).toEqual([true])
		finish()
		await second
		await flushPromises()
		expect(wrapper.emitted('update:busy').at(-1)).toEqual([false])
	})

	it('leaves a dropped folder out and says so', async () => {
		const wrapper = mounted()
		const folder = new File([''], 'photos')
		const items = [
			{ kind: 'file', webkitGetAsEntry: () => ({ isDirectory: true }), getAsFile: () => folder },
			{ kind: 'file', webkitGetAsEntry: () => ({ isDirectory: false }), getAsFile: () => file('a.txt') },
			{ kind: 'string', webkitGetAsEntry: () => null, getAsFile: () => null },
		]

		await wrapper
			.find('[data-testid="attachment-dropzone"]')
			.trigger('drop', { dataTransfer: { items, files: [folder] } })

		expect(wrapper.text()).toContain('a.txt')
		expect(wrapper.text()).not.toContain('photos')
		expect(wrapper.find('[data-testid="attachment-dropzone-folders"]').exists()).toBe(
			true,
		)
	})

	it('keeps a file dropped beside the zone from replacing the page', () => {
		const wrapper = mount(AttachmentDropZone, {
			global: { stubs },
			attachTo: document.body,
		})
		const drop = (types) => {
			const event = new Event('drop', { bubbles: true, cancelable: true })
			event.dataTransfer = { types }
			document.body.dispatchEvent(event)
			return event.defaultPrevented
		}

		expect(drop(['Files'])).toBe(true)
		expect(drop(['text/plain'])).toBe(false)
		wrapper.unmount()
		expect(drop(['Files'])).toBe(false)
	})

	it('resolves true with nothing waiting', async () => {
		const wrapper = mounted()
		expect(await wrapper.vm.uploadPending('s1')).toBe(true)
		expect(store.uploadFile).not.toHaveBeenCalled()
	})
})
