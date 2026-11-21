/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SecretListItem edges: a configured favicon that fails to load falls back to
 * the type icon; a failed star says so and frees the button; keys pressed on
 * the star do not open the row; a copy is passed on; a secret without a key
 * copies ''; a flagged item copies through the re-prompt guard; and the
 * re-prompt marker shows only for a flagged item.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-secret-list-rows-must-be-keyboard-operable
 * @spec openspec/changes/archive/2026-06-14-implement-secrets/specs/secrets/spec.md#requirement-favicon-display
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretListItem from '../../src/components/SecretListItem.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

const { loadState, showError } = vi.hoisted(() => ({
	loadState: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/initial-state', () => ({ loadState }))
vi.mock('@nextcloud/dialogs', () => ({ showError }))

const CopyButton = {
	name: 'CopyButton',
	props: ['resolve', 'useOnly', 'guard', 'label'],
	emits: ['copied'],
	template:
		'<button class="copy" :data-guarded="String(guard !== null)" @click="$emit(\'copied\')" />',
}

/**
 * Mount a row for this secret with the copy button stubbed.
 *
 * @param {object} secret The secret.
 * @return {object} The wrapper.
 */
function mountRow(secret) {
	return mount(SecretListItem, {
		props: { secret },
		global: { stubs: { CopyButton, StrengthBadge: true } },
	})
}

describe('SecretListItem edges', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		loadState.mockImplementation((_app, _key, fallback) => fallback)
		showError.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('falls back to the type icon when the configured favicon fails to load', async () => {
		loadState.mockImplementation((_app, key, fallback) =>
			key === 'faviconServiceUrl'
				? 'https://icons.example/{domain}.ico'
				: fallback,
		)
		const wrapper = mountRow({
			id: 's-1',
			name: 'GitHub',
			url: 'https://github.com/login',
			typeId: 'type-login',
		})

		const img = wrapper.find('.secret-list-item__icon img')
		expect(img.attributes('src')).toBe('https://icons.example/github.com.ico')

		await img.trigger('error')

		expect(wrapper.find('.secret-list-item__icon img').exists()).toBe(false)
		expect(wrapper.vm.faviconFailed).toBe(true)
	})

	it('says so when the star cannot be changed, and frees the button again', async () => {
		vi.spyOn(useSecretStore(), 'setFavourite').mockRejectedValue(
			new Error('offline'),
		)
		const wrapper = mountRow({ id: 's-2', name: 'Bank', favourite: false })

		await wrapper.find('[data-testid="secret-star-s-2"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not change the favourite')
		expect(wrapper.vm.starring).toBe(false)
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('does not open the row for Enter or Space pressed on the star', async () => {
		const wrapper = mountRow({ id: 's-3', name: 'Mail' })
		const star = wrapper.find('.secret-list-item__star')

		await star.trigger('keydown', { key: 'Enter' })
		await star.trigger('keydown', { key: ' ' })
		await star.trigger('click')

		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('does not open the row for a key event that started inside it', () => {
		const wrapper = mountRow({ id: 's-3b', name: 'Mail' })
		const inner = wrapper.find('.secret-list-item__name').element

		wrapper.vm.onRowActivate({ target: inner, currentTarget: wrapper.element })

		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('passes a copy on as `copied` without opening the row', async () => {
		const wrapper = mountRow({ id: 's-4', name: 'Router' })

		await wrapper.find('.copy').trigger('click')

		expect(wrapper.emitted('copied')).toEqual([[]])
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it("copies '' for a secret that holds no key", async () => {
		const fetchSecret = vi
			.spyOn(useSecretStore(), 'fetchSecret')
			.mockResolvedValue({ id: 's-5', key: null })
		const wrapper = mountRow({ id: 's-5', name: 'Empty' })

		await expect(wrapper.vm.resolveKey()).resolves.toBe('')
		expect(fetchSecret).toHaveBeenCalledWith('s-5')
	})

	it('copies a flagged item through the re-prompt guard and marks it', () => {
		const flagged = mountRow({ id: 's-6', name: 'Root', reprompt: true })
		expect(flagged.find('.copy').attributes('data-guarded')).toBe('true')
		expect(typeof flagged.vm.revealGuard).toBe('function')
		expect(
			flagged
				.find('[data-testid="secret-reprompt-marker"]')
				.attributes('aria-label'),
		).toBe('Asks for your master password')

		const plain = mountRow({ id: 's-7', name: 'Plain' })
		expect(plain.find('.copy').attributes('data-guarded')).toBe('false')
		expect(plain.vm.revealGuard).toBeNull()
		expect(plain.find('[data-testid="secret-reprompt-marker"]').exists()).toBe(
			false,
		)
	})
})
