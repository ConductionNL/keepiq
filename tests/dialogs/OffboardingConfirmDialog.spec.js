/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/OffboardingConfirmDialog.vue`.
 *
 * The last check before an irreversible offboarding: both user ids must be
 * spelled out in the prompt, confirm comes only from the Offboard button, and
 * cancel / dismissal forward `update:open`.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-admin-offboarding
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OffboardingConfirmDialog from '../../src/dialogs/OffboardingConfirmDialog.vue'

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

function mountDialog(t) {
	return mount(OffboardingConfirmDialog, {
		props: { open: true, leavingUserId: 'alice', successorUserId: 'bob' },
		global: { stubs, mixins: t ? [{ methods: { t } }] : [] },
	})
}

describe('OffboardingConfirmDialog', () => {
	it('names the leaving user and the successor in the prompt', () => {
		const t = vi.fn((_app, key) => key)
		mountDialog(t)

		expect(t).toHaveBeenCalledWith(
			'keepiq',
			'Revoke all team-folder access of "{leaving}" and transfer their owned team secrets to "{successor}"? This cannot be undone.',
			{ leaving: 'alice', successor: 'bob' },
		)
	})

	it('emits confirm from the offboard button only', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="offboarding-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('closes through update:open from cancel and from the dialog', async () => {
		const wrapper = mountDialog()

		const cancel = wrapper
			.findAll('button')
			.find((b) => b.text() === 'Cancel')
		await cancel.trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})
