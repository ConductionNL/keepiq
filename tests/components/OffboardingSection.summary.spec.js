/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the post-run summary sentence of the offboarding section.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import OffboardingSection from '../../src/components/settings/OffboardingSection.vue'

function summaryOf(summary) {
	const wrapper = mount(OffboardingSection, {
		shallow: true,
		global: {
			mixins: [
				{
					methods: {
						t: (app, text, vars) => `[${app}] ${text} ${JSON.stringify(vars ?? {})}`,
						n: (app, one, many, count) => `[${app}] ${count === 1 ? one : many} (${count})`,
					},
				},
			],
		},
	})
	wrapper.vm.summary = summary
	return wrapper.vm.summaryText
}

describe('OffboardingSection summaryText', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('is empty before a run', () => {
		expect(summaryOf(null)).toBe('')
	})

	it.each([
		[{ revoked: 3, transferred: 2, skipped: [], membershipsRemoved: 0 }],
		[{ revoked: 0, transferred: 1, skipped: [], membershipsRemoved: 1 }],
		[{ revoked: 1, transferred: 0, skipped: [], membershipsRemoved: 4 }],
		[{ revoked: 2, transferred: 2, skipped: ['s-1'], membershipsRemoved: 0 }],
		[{ revoked: 2, transferred: 2, skipped: ['s-1', 's-2'], membershipsRemoved: 3 }],
	])('builds the sentence for %j', (summary) => {
		expect(summaryOf(summary)).toMatchSnapshot()
	})
})
