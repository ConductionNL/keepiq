/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the footers of the item type dialogs: per state, the rendered
 * footer buttons (label, variant, disabled, icon, test id) and what a click
 * emits and calls on the type store. Set GOLDEN_RECORD=1 to rewrite the
 * fixture, then run again without it.
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */

import { mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretTypeDeleteDialog from '../../src/dialogs/SecretTypeDeleteDialog.vue'
import SecretTypeEditorDialog from '../../src/dialogs/SecretTypeEditorDialog.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const FIXTURE = join(
	process.cwd(),
	'tests/dialogs/fixtures/secretTypeDialogsFooter.golden.json',
)
const RECORD = process.env.GOLDEN_RECORD === '1'
const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

const icon = (name) => ({ template: `<i data-icon="${name}" />` })

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><footer><slot name="actions" /></footer></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		emits: ['click'],
		template:
			'<button :data-variant="variant || \'default\'" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcSelect: { props: ['options', 'modelValue'], template: '<div />' },
	NcTextField: { props: ['modelValue', 'label'], template: '<input />' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		template: '<span><slot /></span>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: icon('loading'),
	CnIcon: { props: ['name'], template: '<i :data-icon="name" />' },
	TrashCanOutline: icon('TrashCanOutline'),
	TrashCanOutlineIcon: icon('TrashCanOutline'),
	ContentSave: icon('ContentSave'),
	ContentSaveIcon: icon('ContentSave'),
}

/**
 * @param {object} wrapper The mounted dialog.
 * @return {Array<object>} The footer buttons as plain data.
 */
function footer(wrapper) {
	return wrapper.findAll('footer button').map((b) => ({
		text: b.text(),
		variant: b.attributes('data-variant'),
		disabled: b.attributes('disabled') !== undefined,
		icon: b.find('[data-icon]').exists()
			? b.find('[data-icon]').attributes('data-icon')
			: null,
		testid: b.attributes('data-testid') ?? null,
	}))
}

/**
 * @param {object} wrapper The mounted dialog.
 * @param {object} store The type store with mocked writes.
 * @return {object} Emitted events and store calls so far.
 */
function effects(wrapper, store) {
	const emitted = {}
	for (const [name, calls] of Object.entries(wrapper.emitted())) {
		if (['update:open', 'saved', 'deleted'].includes(name)) {
			emitted[name] = calls
		}
	}
	return JSON.parse(
		JSON.stringify({
			emitted,
			createType: store.createType.mock.calls,
			updateType: store.updateType.mock.calls,
			deleteType: store.deleteType.mock.calls,
		}),
	)
}

/**
 * @param {string} key The case name.
 * @param {object} value The observed value.
 */
function check(key, value) {
	const plain = JSON.parse(JSON.stringify(value))
	recorded[key] = plain
	if (!RECORD) {
		expect(plain).toEqual(expected[key])
	}
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('item type dialog footers (golden)', () => {
	let store
	let release

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSecretTypeStore()
		store.types = [
			{ id: 'type-login', name: 'login', label: 'Login', scope: 'system' },
		]
		store.createType = vi.fn(async (data) => ({ id: 'new', ...data }))
		store.updateType = vi.fn(async (id, label, fields) => ({
			id,
			label,
			fields,
		}))
		store.deleteType = vi.fn(async () => undefined)
		release = null
	})

	afterAll(() => {
		if (RECORD) {
			writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	const deleteDialog = () =>
		mount(SecretTypeDeleteDialog, {
			props: { open: true, type: { id: 'type-x', label: 'Server' } },
			global: { stubs },
		})

	it('delete: idle footer', () => {
		check('delete idle', footer(deleteDialog()))
	})

	it('delete: cancel closes without a store call', async () => {
		const w = deleteDialog()
		await w.findAll('footer button')[0].trigger('click')
		check('delete cancel', effects(w, store))
	})

	it('delete: confirm deletes, then closes', async () => {
		const w = deleteDialog()
		await w.findAll('footer button')[1].trigger('click')
		await flush()
		check('delete confirm', effects(w, store))
	})

	it('delete: footer while the delete runs, and a cancel click then', async () => {
		store.deleteType = vi.fn(
			() =>
				new Promise((resolve) => {
					release = resolve
				}),
		)
		const w = deleteDialog()
		await w.findAll('footer button')[1].trigger('click')
		await w.vm.$nextTick()
		const busy = footer(w)
		await w.findAll('footer button')[0].trigger('click')
		const during = effects(w, store)
		release()
		await flush()
		check('delete busy', { busy, during, after: effects(w, store) })
	})

	it('delete: a failure shows the error and keeps the dialog open', async () => {
		store.deleteType = vi.fn(async () => {
			throw { response: { data: { message: 'In use' } } }
		})
		const w = deleteDialog()
		await w.findAll('footer button')[1].trigger('click')
		await flush()
		check('delete failure', {
			footer: footer(w),
			note: w.find('.note').text(),
			effects: effects(w, store),
		})
	})

	const editor = (props = {}) =>
		mount(SecretTypeEditorDialog, {
			props: { open: true, ...props },
			global: { stubs },
		})

	it('editor: empty name blocks the save', async () => {
		const w = editor()
		await w.findAll('footer button')[1].trigger('click')
		check('editor empty', { footer: footer(w), effects: effects(w, store) })
	})

	it('editor: a named type saves, then closes', async () => {
		const w = editor()
		w.vm.label = 'Server access'
		await w.vm.$nextTick()
		const ready = footer(w)
		await w.findAll('footer button')[1].trigger('click')
		await flush()
		check('editor create', { ready, effects: effects(w, store) })
	})

	it('editor: an existing type updates', async () => {
		const w = editor({ type: { id: 'type-x', label: 'Server', fields: [] } })
		await w.vm.$nextTick()
		await w.findAll('footer button')[1].trigger('click')
		await flush()
		check('editor update', { footer: footer(w), effects: effects(w, store) })
	})

	it('editor: footer while the save runs, and a cancel click then', async () => {
		store.createType = vi.fn(
			() =>
				new Promise((resolve) => {
					release = resolve
				}),
		)
		const w = editor()
		w.vm.label = 'Server access'
		await w.vm.$nextTick()
		await w.findAll('footer button')[1].trigger('click')
		await w.vm.$nextTick()
		const busy = footer(w)
		await w.findAll('footer button')[0].trigger('click')
		const during = effects(w, store)
		release({ id: 'new' })
		await flush()
		check('editor busy', { busy, during, after: effects(w, store) })
	})

	it('editor: cancel closes without a store call', async () => {
		const w = editor()
		await w.findAll('footer button')[0].trigger('click')
		check('editor cancel', effects(w, store))
	})
})
