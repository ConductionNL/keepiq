/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the footers of the folder and vault dialogs (create, edit,
 * delete, delete with subfolders): per state the footer buttons (label,
 * variant, disabled, icon, test id) and what a click emits and calls on the
 * folder store. A delete runs only on a click of the delete button. Set
 * GOLDEN_RECORD=1 to rewrite the fixture, then run again without it.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
 */

import { mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest'
import FolderCreateDialog from '../../src/dialogs/FolderCreateDialog.vue'
import FolderDeleteConfirmDialog from '../../src/dialogs/FolderDeleteConfirmDialog.vue'
import FolderEditDialog from '../../src/dialogs/FolderEditDialog.vue'
import SubfolderResolutionDialog from '../../src/modals/SubfolderResolutionDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'

const FIXTURE = join(
	process.cwd(),
	'tests/dialogs/fixtures/vaultDialogFooters.golden.json',
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
	NcSelect: {
		props: ['options', 'modelValue', 'inputLabel'],
		template: '<div />',
	},
	NcTextField: { props: ['modelValue', 'label'], template: '<input />' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: icon('loading'),
	CnIcon: { props: ['name'], template: '<i :data-icon="name" />' },
	CnIconColorPicker: { template: '<div />' },
	DestinationSelect: { props: ['modelValue'], template: '<div />' },
	SafeIcon: icon('Safe'),
	Safe: icon('Safe'),
	FolderPlusIcon: icon('FolderPlus'),
	FolderPlus: icon('FolderPlus'),
	ContentSaveOutlineIcon: icon('ContentSaveOutline'),
	ContentSaveOutline: icon('ContentSaveOutline'),
	TrashCanOutlineIcon: icon('TrashCanOutline'),
	TrashCanOutline: icon('TrashCanOutline'),
	DeleteIcon: icon('Delete'),
	Delete: icon('Delete'),
}

const global = {
	stubs,
	mixins: [
		{ methods: { t: (app, s) => s, n: (app, s, p, c) => (c === 1 ? s : p) } },
	],
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
 * @param {object} store The folder store with mocked writes.
 * @return {object} Emitted events and store calls so far.
 */
function effects(wrapper, store) {
	const emitted = {}
	for (const [name, calls] of Object.entries(wrapper.emitted())) {
		if (['update:open', 'saved', 'deleted', 'close'].includes(name)) {
			emitted[name] = calls
		}
	}
	return JSON.parse(
		JSON.stringify({
			emitted,
			createFolder: store.createFolder.mock.calls,
			updateFolder: store.updateFolder.mock.calls,
			deleteFolder: store.deleteFolder.mock.calls,
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

const DIALOGS = {
	'folder create, vault level': {
		component: FolderCreateDialog,
		props: {},
		write: 'createFolder',
		prepare: (vm) => {
			vm.name = 'Work'
		},
	},
	'folder create, nested': {
		component: FolderCreateDialog,
		props: { parentId: 'v1' },
		write: 'createFolder',
		prepare: (vm) => {
			vm.name = 'Sub'
		},
	},
	'folder edit': {
		component: FolderEditDialog,
		props: { folder: { id: 'f1', name: 'Old', parentId: 'v1' } },
		write: 'updateFolder',
		prepare: (vm) => {
			vm.name = 'New'
		},
	},
	'folder delete': {
		component: FolderDeleteConfirmDialog,
		props: { folder: { id: 'f1', name: 'Old', parentId: 'v1' } },
		write: 'deleteFolder',
	},
	'vault delete': {
		component: FolderDeleteConfirmDialog,
		props: { folder: { id: 'v1', name: 'Vault', parentId: null } },
		write: 'deleteFolder',
	},
	'subfolder delete': {
		component: SubfolderResolutionDialog,
		props: {
			open: true,
			folderId: 'f1',
			children: {
				directSecretCount: 2,
				subfolders: [{ id: 'f2', name: 'Inner' }],
			},
		},
		write: 'deleteFolder',
	},
}

describe('vault dialog footers (golden)', () => {
	let store
	let release

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useFolderStore()
		store.folders = [{ id: 'v1', name: 'Vault', parentId: null }]
		store.fetchFolders = vi.fn().mockResolvedValue()
		store.createFolder = vi.fn(async (name) => ({ id: 'new', name }))
		store.updateFolder = vi.fn(async (id, data) => ({ id, ...data }))
		store.deleteFolder = vi.fn(async () => undefined)
		release = null
	})

	afterAll(() => {
		if (RECORD) {
			writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const [name, d] of Object.entries(DIALOGS)) {
		const open = async () => {
			const wrapper = mount(d.component, { props: d.props, global })
			await flush()
			d.prepare?.(wrapper.vm)
			await wrapper.vm.$nextTick()
			return wrapper
		}

		it(`${name}: idle footer, and cancel`, async () => {
			const wrapper = await open()
			const idle = footer(wrapper)
			await wrapper.findAll('footer button')[0].trigger('click')
			check(`${name} cancel`, { idle, effects: effects(wrapper, store) })
		})

		it(`${name}: confirm`, async () => {
			const wrapper = await open()
			const before = effects(wrapper, store)
			await wrapper.findAll('footer button')[1].trigger('click')
			await flush()
			check(`${name} confirm`, { before, after: effects(wrapper, store) })
		})

		it(`${name}: footer while the write runs, and a cancel click then`, async () => {
			store[d.write] = vi.fn(
				() =>
					new Promise((resolve) => {
						release = resolve
					}),
			)
			const wrapper = await open()
			await wrapper.findAll('footer button')[1].trigger('click')
			await wrapper.vm.$nextTick()
			const busy = footer(wrapper)
			await wrapper.findAll('footer button')[0].trigger('click')
			const during = effects(wrapper, store)
			release({ id: 'done' })
			await flush()
			check(`${name} busy`, { busy, during, after: effects(wrapper, store) })
		})

		it(`${name}: a failed write keeps the dialog open`, async () => {
			store[d.write] = vi.fn(async () => {
				throw { response: { data: { message: 'Refused' } } }
			})
			const wrapper = await open()
			await wrapper.findAll('footer button')[1].trigger('click')
			await flush()
			check(`${name} failure`, {
				footer: footer(wrapper),
				note: wrapper.find('.note').exists()
					? wrapper.find('.note').text()
					: null,
				effects: effects(wrapper, store),
			})
		})
	}

	it('folder create: an empty name blocks the create', async () => {
		const wrapper = mount(FolderCreateDialog, { props: {}, global })
		await flush()
		await wrapper.findAll('footer button')[1].trigger('click')
		check('folder create empty', {
			footer: footer(wrapper),
			effects: effects(wrapper, store),
		})
	})
})
