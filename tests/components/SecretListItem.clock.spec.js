/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins that the test clock from `tests/vitest/monotonicNow.js` keeps a row
 * click when the wall clock steps back between mount and trigger.
 *
 * @spec exclude test harness regression, not product behaviour
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretListItem from '../../src/components/SecretListItem.vue'
import { monotonic } from '../vitest/monotonicNow.js'

const secret = { id: 's-42', name: 'AWS', url: null, typeId: 'type-login' }

/**
 * Mount the row at clock time 10,000 and click it at 9,000, on a monotonic clock.
 *
 * @return {Promise<object>} The mounted wrapper, after the click.
 */
async function clickAfterClockStepsBack() {
	let time = 10000
	vi.spyOn(Date, 'now').mockImplementation(monotonic(() => time))
	// Vue caches the time until the next microtask; start from a fresh read.
	await Promise.resolve()
	const wrapper = mount(SecretListItem, { propsData: { secret } })
	time = 9000
	await wrapper.find('.secret-list-item').trigger('click')
	return wrapper
}

describe('SecretListItem row click and the test clock', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('emits `open` after the clock stepped back when the clock is monotonic', async () => {
		const wrapper = await clickAfterClockStepsBack()

		expect(wrapper.emitted('open')).toEqual([['s-42']])
	})

	it('runs the suite on a monotonic Date.now', () => {
		const readings = Array.from({ length: 50 }, () => Date.now())

		expect(readings).toEqual([...readings].sort((a, b) => a - b))
		expect(Date.now.name).not.toBe('now')
	})
})

describe('monotonic', () => {
	it('returns the highest reading so far', () => {
		const raw = [5, 3, 7, 7, 1, 8]
		const clock = monotonic(() => raw.shift())

		expect([clock(), clock(), clock(), clock(), clock(), clock()]).toEqual([
			5, 5, 7, 7, 7, 8,
		])
	})
})
