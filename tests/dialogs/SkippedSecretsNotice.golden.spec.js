/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the notice on secrets left out of an export, in the export,
 * GDPR export and CXP transfer dialogs: per dialog and skipped count, the
 * rendered dialog markup, whether the export or send may start, and that a
 * click on the acknowledgement is what releases it. Set GOLDEN_RECORD=1 to
 * rewrite the fixture, then run again without it.
 *
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
 */

import { mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest'
import CxpTransferDialog from '../../src/dialogs/CxpTransferDialog.vue'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import GdprExportDialog from '../../src/dialogs/GdprExportDialog.vue'
import { useSessionStore } from '../../src/store/modules/session.js'

const FIXTURE = join(
	process.cwd(),
	'tests/dialogs/fixtures/skippedSecretsNotice.golden.json',
)
const RECORD = process.env.GOLDEN_RECORD === '1'
const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="dialog" :data-name="name"><slot /><footer><slot name="actions" /></footer></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template:
			'<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcSelect: {
		props: ['options', 'inputLabel', 'modelValue'],
		template: '<div class="select" />',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		template: '<input :aria-label="label" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :aria-label="label" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value', 'name', 'type'],
		emits: ['update:modelValue'],
		template:
			'<label class="checkbox" :data-checked="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>',
	},
}

/**
 * Nextcloud's plural helper, substituting the count.
 *
 * @param {string} app The app id.
 * @param {string} singular The singular text.
 * @param {string} plural The plural text.
 * @param {number} count The count.
 * @return {string}
 */
function n(app, singular, plural, count) {
	return (count === 1 ? singular : plural).replace('%n', String(count))
}

const global = { stubs, mixins: [{ methods: { t: (app, s) => s, n } }] }

/**
 * @param {Node} node A DOM node.
 * @return {string} Its markup with attributes in name order, without scope
 * ids, comments or layout whitespace.
 */
function serialize(node) {
	if (node.nodeType === 3) {
		return node.textContent.trim() === '' ? '' : node.textContent
	}
	if (node.nodeType !== 1) {
		return ''
	}
	const attrs = [...node.attributes]
		.filter((attr) => !attr.name.startsWith('data-v-'))
		.map((attr) => `${attr.name}="${attr.value}"`)
		.sort()
	const tag = node.tagName.toLowerCase()
	const inner = [...node.childNodes].map(serialize).join('')
	return `<${[tag, ...attrs].join(' ')}>${inner}</${tag}>`
}

/**
 * @param {object} wrapper The mounted dialog.
 * @return {string} The dialog markup, normalised.
 */
function html(wrapper) {
	return serialize(wrapper.element)
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

const SECRETS = [
	{ id: 's1', name: 'GitHub' },
	{ id: 's3', name: 'AWS' },
]

beforeEach(() => {
	setActivePinia(createPinia())
	useSessionStore().cryptoKey = { fake: 'unlocked' }
})

afterAll(() => {
	if (RECORD) {
		writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
	}
})

describe('skipped secrets notice (golden)', () => {
	for (const skipped of [0, 1, 2]) {
		it(`export dialog, ${skipped} skipped`, async () => {
			const wrapper = mount(ExportDialog, {
				props: { open: true, secrets: SECRETS, folders: [], skipped },
				global,
			})
			wrapper.vm.mode = 'encrypted-backup'
			wrapper.vm.passphrase = 'a-much-longer-unpredictable-passphrase'
			wrapper.vm.passphraseScore = 3
			await wrapper.vm.$nextTick()
			const before = { html: html(wrapper), canSubmit: wrapper.vm.canSubmit }
			const ack = wrapper.find('[data-testid="export-skipped-ack"]')
			let after = null
			if (ack.exists()) {
				await ack.trigger('click')
				after = {
					canSubmit: wrapper.vm.canSubmit,
					acknowledged: wrapper.vm.skippedAcknowledged,
					checkbox: ack.attributes('data-checked'),
				}
				await ack.trigger('click')
				after.again = wrapper.vm.canSubmit
			}
			wrapper.vm.onUpdateOpen(false)
			check(`export ${skipped}`, {
				before,
				after,
				reset: wrapper.vm.skippedAcknowledged,
			})
		})

		it(`gdpr dialog, ${skipped} skipped`, async () => {
			const wrapper = mount(GdprExportDialog, {
				props: { open: true, secrets: SECRETS, folders: [], skipped },
				global,
			})
			const exportGdprPackage = vi
				.spyOn(wrapper.vm.exportStore, 'exportGdprPackage')
				.mockResolvedValue()
			await wrapper.vm.$nextTick()
			const before = {
				html: html(wrapper),
				canDownload: wrapper.vm.canDownload,
			}
			await wrapper.find('[data-testid="gdpr-download"]').trigger('click')
			const heldCalls = exportGdprPackage.mock.calls.length
			const ack = wrapper.find('[data-testid="gdpr-skipped-ack"]')
			if (ack.exists()) {
				await ack.trigger('click')
			}
			const afterAck = {
				html: html(wrapper),
				canDownload: wrapper.vm.canDownload,
			}
			await wrapper.find('[data-testid="gdpr-download"]').trigger('click')
			await wrapper.vm.$nextTick()
			check(`gdpr ${skipped}`, {
				before,
				heldCalls,
				afterAck,
				calls: exportGdprPackage.mock.calls.map(([secrets, folders]) => [
					secrets === null ? null : secrets.map((s) => s.id),
					folders,
				]),
				emitted: wrapper.emitted('update:open') ?? [],
			})
		})

		it(`gdpr dialog locked, ${skipped} skipped`, async () => {
			useSessionStore().cryptoKey = null
			const wrapper = mount(GdprExportDialog, {
				props: { open: true, secrets: SECRETS, folders: [], skipped },
				global,
			})
			await wrapper.vm.$nextTick()
			check(`gdpr locked ${skipped}`, {
				locked: wrapper.vm.locked,
				html: html(wrapper),
				canDownload: wrapper.vm.canDownload,
			})
		})

		it(`cxp dialog, ${skipped} skipped`, async () => {
			const wrapper = mount(CxpTransferDialog, {
				props: { open: true, secrets: SECRETS, folders: [], skipped },
				global,
			})
			wrapper.vm.direction = 'send'
			wrapper.vm.sendPairingId = 'pair-1'
			wrapper.vm.masterPassword = 'attempt'
			await wrapper.vm.$nextTick()
			const send = () =>
				wrapper.find('[data-testid="cxp-do-send"]').attributes('disabled')
				!== undefined
			const before = { html: html(wrapper), sendDisabled: send() }
			const ack = wrapper.find('[data-testid="cxp-skipped-ack"]')
			let afterAck = null
			let afterUntick = null
			if (ack.exists()) {
				await ack.trigger('click')
				afterAck = {
					sendDisabled: send(),
					acknowledged: wrapper.vm.skippedAcknowledged,
				}
				await ack.trigger('click')
				afterUntick = {
					sendDisabled: send(),
					acknowledged: wrapper.vm.skippedAcknowledged,
				}
			}
			check(`cxp ${skipped}`, { before, afterAck, afterUntick })
		})
	}
})
