/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/PrivateKeyDownloadDialog.vue`.
 *
 * This dialog shows a ONE-TIME private key that cannot be recovered. Its first
 * cut was a bare <section role="dialog"> in document flow, which painted the
 * key below the fold where nobody saw it. What these tests pin:
 *
 *  - The dialog renders through NcDialog with `noClose`, so it is a real
 *    overlay AND the library's own escape hatches (close button, Esc, outside
 *    click) stay shut — the acknowledgment gate is the only way out.
 *  - Dismiss is disabled until the acknowledgment box is ticked.
 *  - Reopening for a new key starts unacknowledged again.
 *
 * The `@nextcloud/vue` design primitives resolve to the flat stub dict in
 * `tests/vitest/stubs/nextcloud-vue.js` (vitest alias): NcDialog renders its
 * slots in place (no teleport), and undeclared props like `noClose` fall
 * through onto its root element as attributes.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PrivateKeyDownloadDialog from '../../src/dialogs/PrivateKeyDownloadDialog.vue'

const KEY = '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----'

/**
 * Mount the dialog open with a key, as the parent views render it.
 *
 * @param {object} propsData Extra props to merge over the defaults.
 * @return {object} The wrapper.
 */
function mountDialog(propsData = {}) {
	return mount(PrivateKeyDownloadDialog, {
		propsData: { open: true, privateKey: KEY, ...propsData },
	})
}

describe('PrivateKeyDownloadDialog', () => {
	it('renders as an NcDialog overlay with every library escape hatch shut', () => {
		const wrapper = mountDialog()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.attributes('data-open')).toBe('true')
		// `noClose` is not declared on the stub, so it falls through as an
		// attribute — its presence pins that the real dialog suppresses the
		// close button, Esc and outside clicks.
		expect(dialog.attributes('noclose')).toBe('true')
	})

	it('shows the key and the unmissable one-time warning', () => {
		const wrapper = mountDialog()
		expect(
			wrapper.find('[data-testid="private-key-textarea"]').element.value,
		).toBe(KEY)
		expect(wrapper.find('[data-testid="private-key-warning"]').text()).toContain(
			'cannot be recovered',
		)
	})

	it('keeps Dismiss locked until the acknowledgment is ticked', async () => {
		const wrapper = mountDialog()
		const dismiss = wrapper.find('[data-testid="private-key-dismiss"]')
		expect(dismiss.attributes('disabled')).toBeDefined()

		wrapper
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
			.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(dismiss.attributes('disabled')).toBeUndefined()
		await dismiss.trigger('click')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('starts unacknowledged again when reopened for a new key', async () => {
		const wrapper = mountDialog()
		wrapper
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
			.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(
			wrapper
				.find('[data-testid="private-key-dismiss"]')
				.attributes('disabled'),
		).toBeDefined()
	})

	it('copies the key to the clipboard and confirms on the button', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.assign(navigator, { clipboard: { writeText } })

		const wrapper = mountDialog()
		await wrapper.find('[data-testid="private-key-copy"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(writeText).toHaveBeenCalledWith(KEY)
		expect(wrapper.find('[data-testid="private-key-copy"]').text()).toContain(
			'Copied!',
		)
	})
})
