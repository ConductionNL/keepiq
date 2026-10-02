/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/KeyGeneratorModal.vue`: the key-generation
 * modal that generates in the browser and emits the plaintext to the parent
 * on "Use". It must never send a request to generate: the server would see
 * the value.
 *
 * What these lock down:
 *  - clicking Generate makes no request and renders a key that follows the
 *    basic options (length, symbols, exclusions);
 *  - the regex override generates from the pattern alone;
 *  - a refused request surfaces in the NcNoteCard;
 *  - the org policy floor fetched on mount clamps the generated length;
 *  - passphrase mode makes words, and is not offered when the policy
 *    switches it off;
 *  - clicking Use emits `generated` with the previewed key and closes the
 *    dialog (`update:open` false).
 *
 * Running under jsdom — mounts the SFC with shallow stubs for `@nextcloud/vue`
 * components so the design-system tree isn't pulled into the bundle.
 *
 * @spec openspec/changes/implement-key-generator/tasks.md#8.1
 * @spec openspec/changes/implement-key-generator/tasks.md#8.2
 * @spec openspec/specs/key-generator/spec.md#requirement-frontend-integration
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KeyGeneratorModal from '../../src/dialogs/KeyGeneratorModal.vue'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: ['checked', 'type'],
		template: '<label class="nc-checkbox-stub"><slot /></label>',
	},
	NcInputField: {
		props: [
			'value',
			'label',
			'min',
			'max',
			'type',
			'helperText',
			'readOnly',
			'showTrailingButton',
			'trailingButtonLabel',
		],
		template: '<div class="nc-input-stub" :data-label="label">{{ value }}</div>',
	},
	NcLoadingIcon: { template: '<span class="nc-loading-stub" />' },
	NcNoteCard: {
		props: ['type'],
		template: '<div class="nc-note-card-stub" :data-type="type"><slot /></div>',
	},
	ContentCopy: { template: '<i class="icon-copy" />' },
	Dice5: { template: '<i class="icon-dice5" />' },
}

describe('KeyGeneratorModal', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('generate(): makes no request and follows the basic options', async () => {
		const post = vi.spyOn(axios, 'post')

		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.lengthInput = 24
		wrapper.vm.includeSpecialCharacters = false
		wrapper.vm.excludedCharacters = '0Ol1I'
		await wrapper.vm.generate()

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.generatedKey).toMatch(/^[A-Za-z0-9]{24}$/)
		expect(wrapper.vm.generatedKey).not.toMatch(/[0Ol1I]/)
		expect(wrapper.vm.error).toBeNull()
	})

	it('generate(): when regex is set, generates from the pattern alone', async () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.lengthInput = 99
		wrapper.vm.regex = '^[A-Z0-9]{12}$'
		await wrapper.vm.generate()

		expect(wrapper.vm.generatedKey).toMatch(/^[A-Z0-9]{12}$/)
	})

	it('generate(): a refused request surfaces in the NcNoteCard', async () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.lengthInput = 6
		await wrapper.vm.generate()

		expect(wrapper.vm.generatedKey).toBe('')
		expect(wrapper.vm.error).toBe('Length must be at least 8 characters')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.nc-note-card-stub').exists()).toBe(true)
	})

	it('generate(): the org policy floor clamps the length', async () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.policy = {
			policy_enabled: true,
			generator_min_length: 30,
			generator_require_digit: true,
		}
		wrapper.vm.lengthInput = 10
		await wrapper.vm.generate()

		expect(wrapper.vm.generatedKey).toHaveLength(30)
		expect(wrapper.vm.generatedKey).toMatch(/[0-9]/)
	})

	it('generate(): passphrase mode makes words from the options, without a request', async () => {
		const post = vi.spyOn(axios, 'post')
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.mode = 'passphrase'
		wrapper.vm.wordsInput = 6
		wrapper.vm.separator = ' '
		wrapper.vm.capitalise = true
		await wrapper.vm.generate()

		expect(post).not.toHaveBeenCalled()
		const words = wrapper.vm.generatedKey.split(' ')
		expect(words).toHaveLength(6)
		expect(words.every((w) => /^[A-Z][a-z-]+$/.test(w))).toBe(true)
	})

	it('offers Password only when the organisation switched passphrases off', async () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		expect(wrapper.vm.passphraseOffered).toBe(true)
		wrapper.vm.policy = {
			policy_enabled: true,
			generator_allow_passphrase: false,
		}
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.passphraseOffered).toBe(false)
		expect(wrapper.find('[data-testid="mode-passphrase"]').exists()).toBe(false)
		wrapper.vm.mode = 'passphrase'
		await wrapper.vm.generate()
		expect(wrapper.vm.generatedKey).toMatch(/^.{16}$/)
	})

	it('use(): emits the generated key and closes the dialog', async () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.generatedKey = 'preview-key'
		wrapper.vm.use()

		const emitted = wrapper.emitted('generated')
		expect(emitted).toBeTruthy()
		expect(emitted[0]).toEqual(['preview-key'])

		const update = wrapper.emitted('update:open')
		expect(update).toBeTruthy()
		expect(update[update.length - 1]).toEqual([false])
	})

	it('use(): no-op when no key has been generated yet', () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.use()

		expect(wrapper.emitted('generated')).toBeFalsy()
	})

	it('reset() clears the preview and error when the dialog closes', () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.generatedKey = 'old-key'
		wrapper.vm.error = 'boom'
		wrapper.vm.onUpdateOpen(false)

		expect(wrapper.vm.generatedKey).toBe('')
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.loading).toBe(false)
	})
})
