/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the exact options `KeyGeneratorModal.generate()` hands to the
 * generator, and the failure fallbacks.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KeyGeneratorModal from '../../src/dialogs/KeyGeneratorModal.vue'
import { generateKey, generatePassphrase } from '../../src/generator/generator.js'

vi.mock('../../src/generator/generator.js', async (importOriginal) => ({
	...(await importOriginal()),
	generateKey: vi.fn(() => 'generated-key'),
	generatePassphrase: vi.fn(() => 'some words here'),
}))
vi.mock('../../src/policy/policy.js', () => ({ fetchPolicy: vi.fn(async () => null) }))

function mountModal() {
	return mount(KeyGeneratorModal, { propsData: { open: true }, shallow: true })
}

describe('KeyGeneratorModal generate', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('passes the password options and the policy', async () => {
		const wrapper = mountModal()
		const policy = { policy_enabled: true }
		Object.assign(wrapper.vm, { policy, lengthInput: '20', includeSpecialCharacters: false, excludedCharacters: 'Il' })
		await wrapper.vm.generate()
		expect(generateKey).toHaveBeenCalledWith({ length: 20, includeSpecialCharacters: false, excludedCharacters: 'Il' }, policy)
		expect(generatePassphrase).not.toHaveBeenCalled()
		expect(wrapper.vm.generatedKey).toBe('generated-key')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe(null)
	})

	it('passes only the pattern when a regex is set', async () => {
		const wrapper = mountModal()
		Object.assign(wrapper.vm, { regex: '^a+$', lengthInput: '20' })
		await wrapper.vm.generate()
		expect(generateKey.mock.calls[0][0]).toEqual({ regex: '^a+$' })
	})

	it('passes the passphrase options in passphrase mode', async () => {
		const wrapper = mountModal()
		Object.assign(wrapper.vm, { mode: 'passphrase', wordsInput: '5', separator: '.', capitalise: true, includeNumber: true })
		await wrapper.vm.generate()
		expect(generatePassphrase).toHaveBeenCalledWith({ words: 5, separator: '.', capitalise: true, includeNumber: true }, wrapper.vm.policy)
		expect(generateKey).not.toHaveBeenCalled()
		expect(wrapper.vm.generatedKey).toBe('some words here')
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([
		[new Error('too short'), 'too short'],
		[{}, 'Failed to generate key'],
	])('clears the key and shows the failure %#', async (failure, text) => {
		generateKey.mockImplementationOnce(() => {
			throw failure
		})
		const wrapper = mountModal()
		wrapper.vm.generatedKey = 'old'
		await wrapper.vm.generate()
		expect(wrapper.vm.generatedKey).toBe('')
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.loading).toBe(false)
	})
})
