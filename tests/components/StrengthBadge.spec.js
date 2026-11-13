/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/StrengthBadge.vue`.
 *
 * The badge reads its score from the in-memory health store's `scoreById`
 * getter. Covered: nothing renders for an unscored secret (absent or null
 * score), each zxcvbn score 0-4 maps to its severity class and accessible
 * label (also used as the title), an out-of-range score falls back to the
 * "danger" / "Very weak" pair, and the badge reacts when the store's findings
 * change (e.g. cleared on lock).
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import StrengthBadge from '../../src/components/StrengthBadge.vue'
import { useHealthStore } from '../../src/store/modules/health.js'

describe('StrengthBadge', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useHealthStore()
	})

	/**
	 * Mount a badge for one secret.
	 *
	 * @param {string} secretId The secret id.
	 * @return {object} The wrapper.
	 */
	function mountBadge(secretId) {
		return mount(StrengthBadge, { props: { secretId } })
	}

	it('renders nothing when the secret has no score', () => {
		store.findings = [{ id: 'other', score: 4, flags: [] }]
		expect(mountBadge('s1').find('.strength-badge').exists()).toBe(false)
	})

	it('renders nothing when the finding score is null (unscored key material)', () => {
		store.findings = [{ id: 's1', score: null, flags: [] }]
		expect(mountBadge('s1').find('.strength-badge').exists()).toBe(false)
	})

	it.each([
		[0, 'danger', 'Very weak'],
		[1, 'danger', 'Weak'],
		[2, 'warning', 'Fair'],
		[3, 'success', 'Strong'],
		[4, 'success', 'Very strong'],
	])('maps score %i to %s / %s', (score, severity, label) => {
		store.findings = [{ id: 's1', score, flags: [] }]
		const badge = mountBadge('s1').find('[data-testid="strength-badge-s1"]')

		expect(badge.exists()).toBe(true)
		expect(badge.classes()).toContain(`strength-badge--${severity}`)
		expect(badge.text()).toBe(label)
		expect(badge.attributes('title')).toBe(label)
	})

	it('falls back to danger / Very weak for an out-of-range score', () => {
		store.findings = [{ id: 's1', score: 9, flags: [] }]
		const badge = mountBadge('s1').find('.strength-badge')

		expect(badge.classes()).toContain('strength-badge--danger')
		expect(badge.text()).toBe('Very weak')
	})

	it('updates and disappears as the store findings change', async () => {
		store.findings = [{ id: 's1', score: 1, flags: [] }]
		const wrapper = mountBadge('s1')
		expect(wrapper.text()).toBe('Weak')

		store.findings = [{ id: 's1', score: 3, flags: [] }]
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toBe('Strong')

		store.findings = []
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.strength-badge').exists()).toBe(false)
	})
})
