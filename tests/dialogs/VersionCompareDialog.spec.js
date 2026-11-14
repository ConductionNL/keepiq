/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The side-by-side comparison dialog: changed rows marked, masked values
 * hidden until revealed, and a plain statement when nothing changed.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VersionCompareDialog from '../../src/dialogs/VersionCompareDialog.vue'

const stubs = {
	NcDialog: { props: ['open', 'name'], template: '<div v-if="open"><slot /></div>' },
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
}

function mountDialog(comparison) {
	return mount(VersionCompareDialog, { props: { comparison }, global: { stubs } })
}

const COMPARISON = {
	left: { name: 'Mail server', login: 'admin', key: 'old-pass', additionalFields: {} },
	right: { name: 'Mail server', login: 'admin', key: 'new-pass', additionalFields: { port: '993' } },
	leftLabel: 'Version 2',
	rightLabel: 'Current value',
}

describe('VersionCompareDialog', () => {
	it('shows both columns and marks the password and the new field as changed', () => {
		const wrapper = mountDialog(COMPARISON)

		expect(wrapper.find('thead').text()).toContain('Version 2')
		expect(wrapper.find('thead').text()).toContain('Current value')
		expect(wrapper.find('[data-testid="version-compare-row-key"]').attributes('data-changed')).toBe('true')
		expect(wrapper.find('[data-testid="version-compare-row-port"]').attributes('data-changed')).toBe('true')
		expect(wrapper.find('[data-testid="version-compare-row-name"]').attributes('data-changed')).toBe('false')
		expect(wrapper.find('[data-testid="version-compare-same"]').exists()).toBe(false)
	})

	it('keeps both passwords masked until Reveal is chosen', async () => {
		const wrapper = mountDialog(COMPARISON)
		const row = () => wrapper.find('[data-testid="version-compare-row-key"]').text()

		expect(row()).not.toContain('old-pass')
		expect(row()).not.toContain('new-pass')
		expect(wrapper.find('[data-testid="version-compare-row-login"]').text()).toContain('admin')

		await wrapper.find('[data-testid="version-compare-reveal"]').trigger('click')
		expect(row()).toContain('old-pass')
		expect(row()).toContain('new-pass')
	})

	it('masks again when another comparison opens', async () => {
		const wrapper = mountDialog(COMPARISON)
		await wrapper.find('[data-testid="version-compare-reveal"]').trigger('click')
		await wrapper.setProps({ comparison: { ...COMPARISON } })

		expect(wrapper.find('[data-testid="version-compare-row-key"]').text()).not.toContain('old-pass')
	})

	it('says that no field changed for two identical states', () => {
		const same = { name: 'N', key: 'k', additionalFields: { a: '1' } }
		const wrapper = mountDialog({ left: same, right: { ...same }, leftLabel: 'Version 1', rightLabel: 'Version 2' })

		expect(wrapper.find('[data-testid="version-compare-same"]').text()).toBe('No field changed between these two.')
	})

	it('renders nothing while closed', () => {
		expect(mountDialog(null).find('table').exists()).toBe(false)
	})
})
