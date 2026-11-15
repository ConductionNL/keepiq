/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/CertificateRenewalChecklistDialog.vue`.
 *
 * The dialog opens exactly when a checklist is given and lists its steps in
 * order; Close and dismissal both emit `close`.
 *
 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-guided-renewal-by-certificate-origin
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CertificateRenewalChecklistDialog from '../../src/dialogs/CertificateRenewalChecklistDialog.vue'

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

function mountDialog(checklist) {
	return mount(CertificateRenewalChecklistDialog, {
		props: { checklist },
		global: { stubs },
	})
}

describe('CertificateRenewalChecklistDialog', () => {
	it('is closed and empty without a checklist', () => {
		const wrapper = mountDialog(null)

		expect(wrapper.find('[data-open]').attributes('data-open')).toBe('false')
		expect(wrapper.find('[data-testid="cert-checklist"]').exists()).toBe(false)
	})

	it('opens on a checklist and lists its steps in order', () => {
		const wrapper = mountDialog({
			steps: ['Generate a CSR', 'Submit it to the issuer', 'Import the result'],
		})

		expect(wrapper.find('[data-open]').attributes('data-open')).toBe('true')
		expect(
			wrapper.findAll('[data-testid="cert-checklist"] li').map((li) => li.text()),
		).toEqual(['Generate a CSR', 'Submit it to the issuer', 'Import the result'])
	})

	it('emits close from the button and from dismissal', async () => {
		const wrapper = mountDialog({ steps: [] })

		await wrapper.find('button').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
