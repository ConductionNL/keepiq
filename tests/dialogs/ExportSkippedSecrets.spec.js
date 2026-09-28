/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An export never leaves out a secret without saying so (keepiq#794).
 *
 * What these lock down:
 *  - SecretList.decryptAllSecrets() counts every secret it cannot decrypt
 *    (a revoked or blocked suite) instead of dropping it in silence.
 *  - openExport() and openCxp() hand that count on, and the SecretList
 *    template binds it to ExportDialog and CxpTransferDialog.
 *  - Both dialogs show how many secrets are not in the export and refuse to
 *    write or send until the user chooses to continue without them; Cancel
 *    and Close stay available.
 *
 * SecretList is exercised options-object style, like the other SecretList
 * specs: mounting the whole list view drags in the folder tree, search and
 * type catalogue, none of which this behaviour touches.
 *
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { parse } from 'vue/compiler-sfc'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CxpTransferDialog from '../../src/dialogs/CxpTransferDialog.vue'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import SecretList from '../../src/views/SecretList.vue'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div />',
	},
	NcTextField: { props: ['modelValue', 'label'], template: '<input />' },
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value', 'name', 'type'],
		template: '<label><slot /></label>',
	},
}

/**
 * Nextcloud's plural helper, reduced to the two English forms.
 *
 * @param {string} app The app id.
 * @param {string} singular The singular source string.
 * @param {string} plural The plural source string.
 * @param {number} count The count.
 * @return {string}
 */
function n(app, singular, plural, count) {
	return (count === 1 ? singular : plural).replace('%n', String(count))
}

const mountOpts = {
	global: { stubs: ncStubs, mocks: { t: (app, s) => s, n } },
}

/**
 * A secret store with three secrets, one of which cannot be decrypted.
 *
 * @return {object}
 */
function storeWithOneUndecryptable() {
	return {
		secrets: [
			{ id: 's1', name: 'GitHub' },
			{ id: 's2', name: 'Under a revoked suite' },
			{ id: 's3', name: 'AWS' },
		],
		fetchAllSecrets: vi.fn().mockResolvedValue(),
		decryptSecret: vi.fn(async (secret) => {
			if (secret.id === 's2') {
				throw new Error('The encryption suite is revoked')
			}
			return { ...secret, key: 'plain-' + secret.id }
		}),
	}
}

/**
 * A `this` for the SecretList export methods, wired to the real
 * decryptAllSecrets().
 *
 * @return {object}
 */
function listContext() {
	const ctx = {
		secretStore: storeWithOneUndecryptable(),
		decryptedSecrets: [],
		skippedSecrets: 0,
		exportOpen: false,
		cxpOpen: false,
	}
	ctx.decryptAllSecrets = () => SecretList.methods.decryptAllSecrets.call(ctx)
	return ctx
}

/**
 * Find the element nodes with a given tag in a compiled SFC template AST.
 *
 * @param {object} node The AST node to search from.
 * @param {string} tag The element tag.
 * @return {Array<object>}
 */
function findElements(node, tag) {
	const found = []
	if (node.type === 1 && node.tag === tag) {
		found.push(node)
	}
	for (const child of node.children || []) {
		found.push(...findElements(child, tag))
	}
	return found
}

/**
 * The expression bound to a prop on an element (`:prop="expr"`), or null.
 *
 * @param {object} element The element node.
 * @param {string} prop The prop name.
 * @return {string|null}
 */
function boundProp(element, prop) {
	const binding = element.props.find(
		(p) => p.type === 7 && p.name === 'bind' && p.arg?.content === prop,
	)
	return binding ? binding.exp.content : null
}

describe('SecretList: secrets that cannot be decrypted are counted (keepiq#794)', () => {
	it('decryptAllSecrets() returns the decrypted secrets and the skipped count', async () => {
		const ctx = listContext()

		const result = await ctx.decryptAllSecrets()

		expect(result.secrets.map((s) => s.id)).toEqual(['s1', 's3'])
		expect(result.skipped).toBe(1)
	})

	it('openExport() hands the skipped count to the export dialog', async () => {
		const ctx = listContext()

		await SecretList.methods.openExport.call(ctx)

		expect(ctx.exportOpen).toBe(true)
		expect(ctx.decryptedSecrets).toHaveLength(2)
		expect(ctx.skippedSecrets).toBe(1)
	})

	it('openCxp() hands the skipped count to the CXP transfer dialog', async () => {
		const ctx = listContext()

		await SecretList.methods.openCxp.call(ctx)

		expect(ctx.cxpOpen).toBe(true)
		expect(ctx.decryptedSecrets).toHaveLength(2)
		expect(ctx.skippedSecrets).toBe(1)
	})

	it('binds the skipped count to both dialogs in the template', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/SecretList.vue'),
			'utf8',
		)
		const ast = parse(source).descriptor.template.ast

		const exportDialogs = findElements(ast, 'ExportDialog')
		const cxpDialogs = findElements(ast, 'CxpTransferDialog')
		expect(exportDialogs).toHaveLength(1)
		expect(cxpDialogs).toHaveLength(1)
		expect(boundProp(exportDialogs[0], 'skipped')).toBe('skippedSecrets')
		expect(boundProp(cxpDialogs[0], 'skipped')).toBe('skippedSecrets')
	})
})

describe('ExportDialog: the skipped count is shown before any file is written', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows how many secrets are left out and blocks Export until the user continues', async () => {
		const wrapper = mount(ExportDialog, {
			props: { open: true, secrets: [], folders: [], skipped: 1 },
			...mountOpts,
		})
		wrapper.vm.mode = 'encrypted-backup'
		wrapper.vm.passphrase = 'a-much-longer-unpredictable-passphrase'
		wrapper.vm.passphraseScore = 3
		await wrapper.vm.$nextTick()

		const warning = wrapper.find('[data-testid="export-skipped-warning"]')
		expect(warning.exists()).toBe(true)
		expect(warning.text()).toContain(
			'1 secret could not be decrypted and is not in this export.',
		)
		// A strong passphrase alone is not enough: the user must choose to
		// continue without the left-out secret.
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.skippedAcknowledged = true
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('shows no warning when every secret was decrypted', async () => {
		const wrapper = mount(ExportDialog, {
			props: { open: true, secrets: [], folders: [], skipped: 0 },
			...mountOpts,
		})
		wrapper.vm.passphrase = 'a-much-longer-unpredictable-passphrase'
		wrapper.vm.passphraseScore = 3
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="export-skipped-warning"]').exists()).toBe(
			false,
		)
		expect(wrapper.vm.canSubmit).toBe(true)
	})
})

describe('CxpTransferDialog: the skipped count is shown before anything is sent', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows how many secrets are left out and blocks sending until the user continues', async () => {
		const wrapper = mount(CxpTransferDialog, {
			props: { open: true, secrets: [], folders: [], skipped: 2 },
			...mountOpts,
		})
		wrapper.vm.direction = 'send'
		wrapper.vm.sendPairingId = 'pair-1'
		wrapper.vm.masterPassword = 'attempt'
		await wrapper.vm.$nextTick()

		const warning = wrapper.find('[data-testid="cxp-skipped-warning"]')
		expect(warning.exists()).toBe(true)
		expect(warning.text()).toContain(
			'2 secrets could not be decrypted and are not in this export.',
		)
		const send = wrapper.find('[data-testid="cxp-do-send"]')
		expect(send.attributes('disabled')).toBeDefined()

		wrapper.vm.skippedAcknowledged = true
		await wrapper.vm.$nextTick()
		expect(send.attributes('disabled')).toBeUndefined()
	})
})
