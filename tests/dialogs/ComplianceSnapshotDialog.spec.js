/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/ComplianceSnapshotDialog.vue`.
 *
 * One snapshot rendered for reading and export. Pins the open state, the
 * section titles, the exact CSV it serialises (quoting included), the print
 * window it writes, and that the export beacon is fired and its failure
 * swallowed.
 *
 * @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
 * @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ComplianceSnapshotDialog from '../../src/dialogs/ComplianceSnapshotDialog.vue'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template:
			'<div :data-open="String(open)"><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div :class="`note note--${type}`" v-bind="$attrs"><slot /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select v-bind="$attrs"></select>',
	},
}

const REPORT = {
	id: 'snap-1',
	generatedAt: '2026-03-04T05:06:07Z',
	generatedBy: 'admin',
	appVersion: '1.2.3',
	configSnapshot: { mfa: true },
	aggregate: {
		adoption: { users: 3, ratio: { a: 1 } },
		customSection: { note: 'say "hi"' },
	},
}

/**
 * jsdom's Blob has no text(); read it the way a browser without it would.
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>} Its text.
 */
function readBlob(blob) {
	return new Promise((resolve) => {
		const reader = new FileReader()
		reader.onload = () => resolve(reader.result)
		reader.readAsText(blob)
	})
}

function mountDialog(report = REPORT) {
	return mount(ComplianceSnapshotDialog, {
		props: { report },
		global: { stubs },
		attachTo: document.body,
	})
}

describe('ComplianceSnapshotDialog', () => {
	let createUrl
	let revokeUrl
	let clicked

	beforeEach(() => {
		createUrl = vi.fn(() => 'blob:fake')
		revokeUrl = vi.fn()
		URL.createObjectURL = createUrl
		URL.revokeObjectURL = revokeUrl
		clicked = []
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
			function () {
				clicked.push({ href: this.href, download: this.download })
			},
		)
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('is closed and empty without a report', () => {
		const wrapper = mountDialog(null)

		expect(wrapper.find('[data-open]').attributes('data-open')).toBe('false')
		expect(wrapper.find('[data-testid="compliance-detail"]').exists()).toBe(false)
	})

	it('renders sections with known titles and falls back to the key', () => {
		const wrapper = mountDialog()

		expect(wrapper.find('[data-open]').attributes('data-open')).toBe('true')
		const headings = wrapper.findAll('h4').map((h) => h.text())
		expect(headings).toEqual(['Adoption', 'customSection', 'Configuration snapshot'])
		expect(wrapper.text()).toContain('{"a":1}')
		expect(wrapper.find('[data-testid="compliance-boundary"]').exists()).toBe(true)
	})

	it('maps every known section to its title', () => {
		const wrapper = mountDialog()

		expect(
			[
				'secretsPerUser',
				'shareHygiene',
				'rotationPosture',
				'auditIntegrity',
				'emergencyAccess',
			].map((s) => wrapper.vm.sectionTitle(s)),
		).toEqual([
			'Secrets per user',
			'Share hygiene',
			'Rotation posture (ciphertext-age, not strength)',
			'Audit-trail integrity',
			'Emergency-access coverage',
		])
	})

	it('exports a quoted CSV named after the report and fires the beacon', async () => {
		let blob
		createUrl.mockImplementation((b) => {
			blob = b
			return 'blob:fake'
		})
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="compliance-export-csv"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(clicked).toEqual([
			{ href: 'blob:fake', download: 'keepiq-compliance-snap-1.csv' },
		])
		expect(revokeUrl).toHaveBeenCalledWith('blob:fake')
		expect(blob.type).toBe('text/csv')
		expect(await readBlob(blob)).toBe(
			[
				'"section","metric","value"',
				'"meta","generatedAt","2026-03-04T05:06:07Z"',
				'"meta","generatedBy","admin"',
				'"meta","appVersion","1.2.3"',
				'"config","mfa","true"',
				'"adoption","users","3"',
				'"adoption","ratio","{""a"":1}"',
				'"customSection","note","say ""hi"""',
			].join('\n'),
		)
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/compliance/reports/snap-1/exported',
			{ format: 'csv' },
		)
	})

	it('exports a CSV of only the meta rows when sections are missing', async () => {
		let blob
		createUrl.mockImplementation((b) => {
			blob = b
			return 'blob:fake'
		})
		const wrapper = mountDialog({ ...REPORT, configSnapshot: null, aggregate: null })

		await wrapper.vm.exportCsv()

		expect((await readBlob(blob)).split('\n')).toHaveLength(4)
	})

	it('prints the rendered detail and records a pdf export', async () => {
		const printWindow = {
			document: { write: vi.fn(), close: vi.fn() },
			print: vi.fn(),
		}
		vi.spyOn(window, 'open').mockReturnValue(printWindow)
		const wrapper = mountDialog()

		await wrapper.vm.exportPdf()

		expect(window.open).toHaveBeenCalledWith('', '_blank')
		const html = printWindow.document.write.mock.calls[0][0]
		expect(html.startsWith('<html><head><title>Keepiq compliance snapshot</title>')).toBe(true)
		expect(html).toContain('Adoption')
		expect(printWindow.document.close).toHaveBeenCalled()
		expect(printWindow.print).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/compliance/reports/snap-1/exported',
			{ format: 'pdf' },
		)
	})

	it('swallows a failed beacon', async () => {
		axios.post.mockRejectedValue(new Error('offline'))
		const wrapper = mountDialog()

		await expect(wrapper.vm.beacon('csv')).resolves.toBeUndefined()
	})

	it('emits close from the close button and from dismissal', async () => {
		const wrapper = mountDialog()

		const close = wrapper.findAll('button').find((b) => b.text() === 'Close')
		await close.trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
