/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/VersionRestoreDialog.vue`: open exactly
 * while a version awaits confirmation, naming that version, and passing
 * cancel, close and confirm up.
 *
 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VersionRestoreDialog from '../../src/dialogs/VersionRestoreDialog.vue'

const stubs = {
	NcDialog: {
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div :data-open="String(open)"><button data-testid="dialog-close" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
}

function mountDialog(version) {
	return mount(VersionRestoreDialog, {
		props: { version },
		global: {
			stubs,
			// The setup's global t() returns the raw template; this one fills it in.
			mixins: [{ methods: { t: (app, s, vars) => s.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)) } }],
		},
	})
}

describe('VersionRestoreDialog', () => {
	it('is closed without a version', () => {
		const wrapper = mountDialog(null)

		expect(wrapper.find('[data-testid="version-restore-dialog"]').attributes('data-open')).toBe('false')
		expect(wrapper.text()).toContain('Restore version 0?')
	})

	it('is open for a version and names it with both consequences', () => {
		const wrapper = mountDialog({ versionNumber: 7 })

		expect(wrapper.find('[data-testid="version-restore-dialog"]').attributes('data-open')).toBe('true')
		expect(wrapper.find('.version-history__confirm').text()).toBe(
			'Restore version 7? The current value is kept as a new version, and shared recipients receive the restored value.',
		)
	})

	it('confirms only from the restore button', async () => {
		const wrapper = mountDialog({ versionNumber: 7 })

		await wrapper.find('[data-testid="version-restore-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('closes from cancel and from the dialog itself', async () => {
		const wrapper = mountDialog({ versionNumber: 7 })

		await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click')
		await wrapper.find('[data-testid="dialog-close"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})
