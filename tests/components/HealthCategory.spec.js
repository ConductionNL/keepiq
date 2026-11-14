/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/HealthCategory.vue`.
 *
 * Covers the presentation contract of one password-health finding category:
 * the title / optional description, the "all clear" line for an empty list,
 * one deep-link button per finding (name, optional folder path, the
 * shared-with count only above one, the breach count only above zero), the
 * `open` event carrying the finding id, and the `testid` prop.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import HealthCategory from '../../src/components/HealthCategory.vue'

/**
 * Mount the category with the given props.
 *
 * @param {object} props Component props.
 * @return {object} The wrapper.
 */
function mountCategory(props) {
	return mount(HealthCategory, { props: { title: 'Weak', ...props } })
}

describe('HealthCategory', () => {
	it('renders the title and the empty line when there are no findings', () => {
		const wrapper = mountCategory({})

		expect(wrapper.find('h3').text()).toBe('Weak')
		expect(wrapper.find('.health-category__description').exists()).toBe(false)
		expect(wrapper.find('.health-category__empty').text()).toBe(
			'No findings in this category.',
		)
		expect(wrapper.find('ul').exists()).toBe(false)
		expect(wrapper.attributes('data-testid')).toBe('health-category')
	})

	it('renders the description and a custom testid when given', () => {
		const wrapper = mountCategory({
			description: 'Passwords that are easy to guess.',
			testid: 'health-weak',
		})

		expect(wrapper.find('.health-category__description').text()).toBe(
			'Passwords that are easy to guess.',
		)
		expect(wrapper.attributes('data-testid')).toBe('health-weak')
	})

	it('renders one button per finding with path, share and breach meta', () => {
		const wrapper = mountCategory({
			findings: [
				{
					id: 's1',
					name: 'Mail',
					folderPath: 'Work / Mail',
					shareCount: 3,
					breach: { count: 7 },
				},
				{ id: 's2', name: 'Bank', folderPath: '', shareCount: 1, breach: { count: 1 } },
				{ id: 's3', name: 'Shop', shareCount: 0, breach: { count: 0 } },
			],
		})

		expect(wrapper.find('.health-category__empty').exists()).toBe(false)
		const items = wrapper.findAll('li')
		expect(items).toHaveLength(3)

		const first = wrapper.find('[data-testid="finding-s1"]')
		expect(first.find('.health-category__name').text()).toBe('Mail')
		expect(first.find('.health-category__path').text()).toBe('Work / Mail')
		const firstMeta = first.findAll('.health-category__meta').map((m) => m.text())
		// The n() stub picks the plural form for counts other than one.
		expect(firstMeta).toEqual(['shared with %n secrets', 'seen %n times in breaches'])

		const second = wrapper.find('[data-testid="finding-s2"]')
		expect(second.find('.health-category__path').exists()).toBe(false)
		// shareCount 1 is not "shared"; a breach count of 1 uses the singular.
		expect(second.findAll('.health-category__meta').map((m) => m.text())).toEqual([
			'seen %n time in breaches',
		])

		const third = wrapper.find('[data-testid="finding-s3"]')
		expect(third.findAll('.health-category__meta')).toHaveLength(0)
	})

	it('emits `open` with the clicked finding id', async () => {
		const wrapper = mountCategory({
			findings: [
				{ id: 's1', name: 'Mail' },
				{ id: 's2', name: 'Bank' },
			],
		})

		await wrapper.find('[data-testid="finding-s2"]').trigger('click')
		await wrapper.find('[data-testid="finding-s1"]').trigger('click')

		expect(wrapper.emitted('open')).toEqual([['s2'], ['s1']])
	})
})
