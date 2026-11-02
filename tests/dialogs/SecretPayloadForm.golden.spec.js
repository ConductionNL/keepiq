/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the value part of the create and edit secret dialogs: per type
 * (login, note, card, identity) the rendered form markup, the form state, and
 * what a save sends to the secret store. The field stubs never render a
 * value, and every secret value in the recorded data is a SHA-256 digest, so
 * no secret ends up in the fixture. Set GOLDEN_RECORD=1 to rewrite the
 * fixture, then run again without it.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
 * @spec openspec/specs/key-generator/spec.md#requirement-frontend-integration
 */

import { mount } from '@vue/test-utils'
import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest'
import KeyGeneratorModal from '../../src/dialogs/KeyGeneratorModal.vue'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: vi.fn().mockResolvedValue(null),
	evaluateScore: vi.fn(() => ({ compliant: true, reason: null })),
	evaluateHibp: vi.fn().mockResolvedValue(null),
}))

const FIXTURE = join(
	process.cwd(),
	'tests/dialogs/fixtures/secretPayloadForm.golden.json',
)
const RECORD = process.env.GOLDEN_RECORD === '1'
const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

const SECRET_KEYS = ['key', 'value', 'login', 'totp', 'number', 'cvv', 'pin', 'bsn']

/**
 * @param {string} text A secret value.
 * @return {string} Its digest, or the empty marker.
 */
function digest(text) {
	if (text === '' || text === null || text === undefined) {
		return `<${text === '' ? 'empty' : String(text)}>`
	}
	return (
		'sha256:'
		+ createHash('sha256').update(String(text)).digest('hex').slice(0, 16)
	)
}

/**
 * @param {*} value Recorded data.
 * @return {*} The same data with every secret field digested.
 */
function mask(value) {
	if (Array.isArray(value)) {
		return value.map(mask)
	}
	if (value && typeof value === 'object') {
		const out = {}
		for (const [k, v] of Object.entries(value)) {
			out[k] =
				SECRET_KEYS.includes(k) && typeof v !== 'object'
					? digest(v)
					: mask(v)
		}
		return out
	}
	return value
}

function field(kind) {
	return {
		props: ['modelValue', 'label', 'required', 'disabled'],
		emits: ['update:modelValue'],
		template: `<input data-kind="${kind}" :aria-label="label" @input="$emit('update:modelValue', $event.target.value)" />`,
	}
}

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size', 'noClose'],
		template:
			'<div :data-name="name"><slot /><footer><slot name="actions" /></footer></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		emits: ['click'],
		template:
			'<button :data-variant="variant" :aria-label="ariaLabel" :title="title" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcSelect: {
		props: [
			'options',
			'reduce',
			'inputLabel',
			'clearable',
			'modelValue',
			'label',
		],
		template: '<div class="select" :data-label="inputLabel" />',
	},
	NcTextField: field('text'),
	NcPasswordField: field('password'),
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcLoadingIcon: { template: '<span class="loading" />' },
	Plus: { template: '<i data-icon="Plus" />' },
	ContentSave: { template: '<i data-icon="ContentSave" />' },
	Dice5: { template: '<i data-icon="Dice5" />' },
	KeyGeneratorModal: {
		props: ['open'],
		emits: ['generated', 'update:open'],
		template: '<div class="generator" :data-open="open" />',
	},
	AuthenticatorKeyField: {
		props: ['value', 'mode', 'saved', 'removeOnly', 'disabled'],
		template: '<div class="authenticator" />',
	},
	TypedFieldsForm: {
		props: ['fields', 'values', 'missing', 'disabled'],
		template: '<div class="typed" />',
	},
	AdditionalFieldsEditor: {
		props: ['members', 'disabled'],
		template: '<div class="additional" />',
	},
	SecretTagsField: {
		props: ['modelValue', 'disabled'],
		template: '<div class="tags" />',
	},
	DestinationSelect: {
		props: ['modelValue', 'mode', 'label', 'onlyIds'],
		template: '<div class="destination" />',
	},
}

const TYPES = [
	{ id: 'type-login', name: 'login', label: 'Login', fields: [] },
	{ id: 'type-note', name: 'note', label: 'Note', fields: [] },
	{ id: 'type-card', name: 'card', label: 'Card', fields: [] },
	{ id: 'type-identity', name: 'identity', label: 'Identity', fields: [] },
]

async function flush(wrapper) {
	for (let i = 0; i < 6; i++) {
		await wrapper.vm.$nextTick()
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
}

/**
 * @param {object} wrapper The mounted dialog.
 * @return {string} The form markup without scope ids, comments or layout whitespace.
 */
function formHtml(wrapper) {
	return wrapper
		.find('.secret-form')
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/>\s+</g, '><')
}

/**
 * @param {object} wrapper The mounted dialog.
 * @param {string} selector A field selector.
 * @param {string} text What to type.
 */
async function type(wrapper, selector, text) {
	const input = wrapper.find(selector)
	input.element.value = text
	await input.trigger('input')
}

/**
 * @param {object} wrapper The mounted dialog.
 * @return {object} The value part of the form state, secrets digested.
 */
function formState(wrapper) {
	const vm = wrapper.vm
	const digestAll = (obj) =>
		Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, digest(v)]))
	return {
		value: digest(vm.value),
		card: digestAll(vm.card),
		identity: digestAll(vm.identity),
		generatorOpen: vm.generatorOpen,
		canSubmit: vm.canSubmit,
	}
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

const CARD_INPUTS = {
	'[data-testid="card-number"]': '4111111111111112',
	'[data-testid="card-expiry"]': '04/29',
	'[data-testid="card-cvv"]': '123',
	'[data-testid="card-pin"]': '9876',
	'[data-testid="card-cardholder"]': 'A. Tester',
}
const IDENTITY_INPUTS = {
	'[data-testid="identity-first-name"]': 'Ada',
	'[data-testid="identity-last-name"]': 'Tester',
	'[data-testid="identity-address"]': 'Teststraat 1',
	'[data-testid="identity-phone"]': '0612345678',
	'[data-testid="identity-email"]': 'ada@example.org',
	'[data-testid="identity-bsn"]': '123456782',
}

beforeEach(() => {
	setActivePinia(createPinia())
	const typeStore = useSecretTypeStore()
	typeStore.types = TYPES.map((t) => ({ ...t }))
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	const folderStore = useFolderStore()
	folderStore.folders = [{ id: 'folder-1', name: 'Vault', parentId: null }]
	folderStore.fetchFolders = vi.fn().mockResolvedValue()
	useSessionStore().cryptoKey = 'UNLOCKED'
	useUserPreferencesStore().ensureLoaded = vi.fn().mockResolvedValue()
	const store = useSecretStore()
	store.createSecret = vi.fn().mockResolvedValue({ id: 'secret-1' })
	store.updateSecret = vi.fn().mockResolvedValue({ id: 's1' })
	store.setTags = vi.fn().mockResolvedValue()
	store.writeBackSettled = vi.fn().mockResolvedValue(null)
	useShareStore().fetchWriteContext = vi
		.fn()
		.mockResolvedValue({ sourceSecretId: 's1' })
})

afterAll(() => {
	if (RECORD) {
		writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
	}
})

describe('create dialog value part (golden)', () => {
	/**
	 * @param {string} typeId The type to pick.
	 * @return {Promise<object>} The wrapper, named and placed in a folder.
	 */
	async function create(typeId) {
		const wrapper = mount(SecretCreateDialog, { global: { stubs } })
		await flush(wrapper)
		wrapper.vm.typeId = typeId
		wrapper.vm.selectedFolderId = 'folder-1'
		await wrapper.vm.$nextTick()
		await type(wrapper, 'input[aria-label="Name"]', 'Item')
		return wrapper
	}

	for (const typeId of ['type-login', 'type-note']) {
		it(`${typeId}: typed value, generator, save`, async () => {
			const wrapper = await create(typeId)
			const empty = { html: formHtml(wrapper), state: formState(wrapper) }
			await type(wrapper, '.secret-form__value-field', 'typed-value-1')
			const typed = formState(wrapper)
			await wrapper
				.find('button[aria-label="Generate a strong key"]')
				.trigger('click')
			const opened = { html: formHtml(wrapper), state: formState(wrapper) }
			const generator = wrapper.findComponent(KeyGeneratorModal)
			generator.vm.$emit('generated', '')
			const ignored = formState(wrapper)
			generator.vm.$emit('generated', 'generated-value-2')
			generator.vm.$emit('update:open', false)
			await wrapper.vm.$nextTick()
			const generated = { html: formHtml(wrapper), state: formState(wrapper) }
			await wrapper.vm.submit()
			check(`create ${typeId}`, {
				empty,
				typed,
				opened,
				ignored,
				generated,
				created: mask(useSecretStore().createSecret.mock.calls),
			})
		})
	}

	it('card: fields, checksum hint, save', async () => {
		const wrapper = await create('type-card')
		const empty = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, '[data-testid="card-number"]', '4111111111111112')
		const badNumber = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, '[data-testid="card-number"]', '4111111111111111')
		const goodNumber = { html: formHtml(wrapper), state: formState(wrapper) }
		for (const [selector, text] of Object.entries(CARD_INPUTS)) {
			await type(wrapper, selector, text)
		}
		const filled = formState(wrapper)
		await wrapper.vm.submit()
		check('create card', {
			empty,
			badNumber,
			goodNumber,
			filled,
			created: mask(useSecretStore().createSecret.mock.calls),
		})
	})

	it('identity: fields, save', async () => {
		const wrapper = await create('type-identity')
		const empty = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, '[data-testid="identity-phone"]', '0612345678')
		const one = formState(wrapper)
		for (const [selector, text] of Object.entries(IDENTITY_INPUTS)) {
			await type(wrapper, selector, text)
		}
		const filled = formState(wrapper)
		await wrapper.vm.submit()
		check('create identity', {
			empty,
			one,
			filled,
			created: mask(useSecretStore().createSecret.mock.calls),
		})
	})
})

describe('edit dialog value part (golden)', () => {
	/**
	 * @param {object} secret The stored secret, decrypted.
	 * @return {Promise<object>} The wrapper after load.
	 */
	async function edit(secret) {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
			id: 's1',
			name: 'Item',
			url: null,
			login: '',
			updatedAt: '2026-10-01T10:00:00+00:00',
			...secret,
		})
		const wrapper = mount(SecretEditDialog, {
			props: { secretId: 's1' },
			global: { stubs },
		})
		await flush(wrapper)
		return wrapper
	}

	it('login: loaded value, generator, save', async () => {
		const wrapper = await edit({
			typeId: 'type-login',
			key: 'stored-value-0',
			login: 'ada',
		})
		const loaded = { html: formHtml(wrapper), state: formState(wrapper) }
		await wrapper
			.find('button[aria-label="Generate a strong key"]')
			.trigger('click')
		const opened = formState(wrapper)
		const generator = wrapper.findComponent(KeyGeneratorModal)
		generator.vm.$emit('generated', 'generated-value-3')
		generator.vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()
		const generated = formState(wrapper)
		await wrapper.vm.submit()
		check('edit login', {
			loaded,
			opened,
			generated,
			updated: mask(useSecretStore().updateSecret.mock.calls),
		})
	})

	it('note: label and an unchanged value sends nothing for the key', async () => {
		const wrapper = await edit({ typeId: 'type-note', key: 'stored-note' })
		const loaded = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, 'input[aria-label="Name"]', 'Renamed')
		await wrapper.vm.submit()
		check('edit note', {
			loaded,
			updated: mask(useSecretStore().updateSecret.mock.calls),
		})
	})

	it('card: seeded from the payload, one field changed', async () => {
		const wrapper = await edit({
			typeId: 'type-card',
			key: JSON.stringify({
				number: '4111111111111111',
				expiry: '01/30',
				cvv: '321',
				pin: '',
				cardholder: 'A. Tester',
			}),
		})
		const loaded = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, '[data-testid="card-number"]', '4111111111111112')
		const badNumber = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, '[data-testid="card-cvv"]', '999')
		const changed = formState(wrapper)
		await wrapper.vm.submit()
		check('edit card', {
			loaded,
			badNumber,
			changed,
			updated: mask(useSecretStore().updateSecret.mock.calls),
		})
	})

	it('identity: seeded from the payload, one field changed', async () => {
		const wrapper = await edit({
			typeId: 'type-identity',
			key: JSON.stringify({
				firstName: 'Ada',
				lastName: 'Tester',
				bsn: '123456782',
			}),
		})
		const loaded = { html: formHtml(wrapper), state: formState(wrapper) }
		await type(wrapper, '[data-testid="identity-email"]', 'ada@example.org')
		const changed = formState(wrapper)
		await wrapper.vm.submit()
		check('edit identity', {
			loaded,
			changed,
			updated: mask(useSecretStore().updateSecret.mock.calls),
		})
	})

	it('a legacy plain value on a card type leaves the card fields empty', async () => {
		const wrapper = await edit({ typeId: 'type-card', key: 'not-json' })
		check('edit card legacy', {
			state: formState(wrapper),
			html: formHtml(wrapper),
		})
	})
})
