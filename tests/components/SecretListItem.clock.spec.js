/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins why "emits `open` with the secret id on row click" failed under load,
 * and that the test clock from `tests/vitest/monotonicNow.js` holds it.
 *
 * Vue ignores an event stamped no later than its listener's attach time, and
 * Vue Test Utils stamps a trigger with `Date.now() + 1`. A wall clock that
 * steps back between mount and trigger therefore drops the click.
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
 * Mount the row at clock time 10,000 and click it at 9,000.
 *
 * @param {(raw: () => number) => () => number} wrap How Date.now reads the stepping clock.
 * @return {Promise<object>} The mounted wrapper, after the click.
 */
async function clickAfterClockStepsBack(wrap) {
	let time = 10000
	vi.spyOn(Date, 'now').mockImplementation(wrap(() => time))
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

	it('drops the click when the raw clock steps back between mount and trigger', async () => {
		const wrapper = await clickAfterClockStepsBack((raw) => raw)

		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('emits `open` after the same step back when the clock is monotonic', async () => {
		const wrapper = await clickAfterClockStepsBack(monotonic)

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
