/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recovery kit panel's actions after a kit is made (crypto-recovery-kit
 * 2.2). Pins: Download hands the browser a text file named
 * keepiq-recovery-kit.txt holding the account, the date and the code, and
 * frees the object URL again; Print writes the same text on a page of its
 * own and prints it, or says the page was blocked; a status lookup that
 * fails counts as no kit; and a server message replaces the generic error.
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-kit-is-created-without-the-code-leaving-the-browser
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const service = vi.hoisted(() => ({
	recoveryKitStatus: vi.fn(),
	createRecoveryKit: vi.fn(),
	recoverWithCode: vi.fn(),
}))

vi.mock('../../src/services/recoveryKit.js', () => service)
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, name) => String(vars[name] ?? match)),
}))

const { default: RecoveryKitPanel } =
	await import('../../src/components/RecoveryKitPanel.vue')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input class="password" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

/**
 * Mount the panel, make a kit with code ABCD-EFGH and return the wrapper.
 *
 * @return {Promise<object>} The wrapper showing the new code.
 */
async function panelWithNewKit() {
	service.recoveryKitStatus.mockResolvedValue(null)
	service.createRecoveryKit.mockResolvedValue({
		code: 'ABCD-EFGH',
		kit: { suiteId: 's-1', createdAt: '2026-11-14T10:00:00+00:00' },
	})
	const wrapper = mount(RecoveryKitPanel, { global: { stubs } })
	await flush()
	await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
	await wrapper
		.get('[data-testid="recovery-kit-password"]')
		.setValue('master password')
	await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
	await flush()
	return wrapper
}

/**
 * Read a Blob as text (jsdom's Blob may lack text()).
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>} Its text.
 */
function blobText(blob) {
	return new Promise((resolve) => {
		const reader = new FileReader()
		reader.onload = () => resolve(reader.result)
		reader.readAsText(blob)
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.clearAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-11-14T12:00:00Z'))
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('RecoveryKitPanel actions', () => {
	it('downloads the kit as a text file and frees the object URL', async () => {
		const blobs = []
		URL.createObjectURL = vi.fn((blob) => {
			blobs.push(blob)
			return 'blob:kit'
		})
		URL.revokeObjectURL = vi.fn()
		const clicked = []
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
			function () {
				clicked.push({ href: this.href, download: this.download })
			},
		)
		const wrapper = await panelWithNewKit()

		await wrapper.get('[data-testid="recovery-kit-download"]').trigger('click')

		expect(clicked).toEqual([
			{ href: 'blob:kit', download: 'keepiq-recovery-kit.txt' },
		])
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:kit')
		expect(blobs).toHaveLength(1)
		expect(blobs[0].type).toBe('text/plain')
		const text = await blobText(blobs[0])
		expect(text).toContain('Account: alice')
		expect(text).toContain('Made on: 2026-11-14')
		expect(text).toContain('Recovery code: ABCD-EFGH')
	})

	it('prints the kit on a page of its own', async () => {
		const page = document.implementation.createHTMLDocument('')
		const printed = vi.fn()
		const open = vi
			.spyOn(window, 'open')
			.mockReturnValue({ document: page, print: printed })
		const wrapper = await panelWithNewKit()

		await wrapper.get('[data-testid="recovery-kit-print"]').trigger('click')

		expect(open).toHaveBeenCalledWith('', '_blank', 'noopener=no')
		expect(page.title).toBe('Keepiq recovery kit')
		expect(page.body.querySelector('pre').textContent).toContain(
			'Recovery code: ABCD-EFGH',
		)
		expect(printed).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('says the print page was blocked when no window opens', async () => {
		vi.spyOn(window, 'open').mockReturnValue(null)
		const wrapper = await panelWithNewKit()

		await wrapper.get('[data-testid="recovery-kit-print"]').trigger('click')

		expect(wrapper.get('[role="alert"]').text()).toBe(
			'The print page was blocked. Download the kit instead.',
		)
		expect(wrapper.get('[data-testid="recovery-kit-code"]').text()).toBe(
			'ABCD-EFGH',
		)
	})

	it('treats a failing status lookup as no kit', async () => {
		service.recoveryKitStatus.mockRejectedValue(new Error('offline'))
		const wrapper = mount(RecoveryKitPanel, { global: { stubs } })
		await flush()

		expect(wrapper.get('[data-testid="recovery-kit-status"]').text()).toBe(
			'You have no recovery kit.',
		)
	})

	it('shows the server message when making the kit is refused', async () => {
		service.recoveryKitStatus.mockResolvedValue(null)
		service.createRecoveryKit.mockRejectedValue({
			response: { data: { message: 'A kit was made a minute ago.' } },
		})
		const wrapper = mount(RecoveryKitPanel, { global: { stubs } })
		await flush()

		await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
		await wrapper
			.get('[data-testid="recovery-kit-password"]')
			.setValue('master password')
		await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
		await flush()

		expect(wrapper.get('[role="alert"]').text()).toBe(
			'A kit was made a minute ago.',
		)
		expect(
			wrapper.get('[data-testid="recovery-kit-password"]').element.value,
		).toBe('')
	})
})
