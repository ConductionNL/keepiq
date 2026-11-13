/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The strength meter's feedback line and its re-evaluation as the password
 * changes: an empty password is never valid and says nothing, a short one
 * names the length floor, zxcvbn's own warning wins over the strength label,
 * and a typed change is re-judged only after the 300ms debounce.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-master-password-policy-mvp
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import zxcvbn from 'zxcvbn'

const fetchPolicy = vi.fn()

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: (...args) => fetchPolicy(...args),
	resetPolicyCache: () => {},
}))

const PasswordStrengthMeter = (
	await import('../../src/components/PasswordStrengthMeter.vue')
).default

/** Let the policy read settle. */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the meter and wait for the policy read.
 *
 * @param {object} props The props.
 * @return {Promise<import('@vue/test-utils').VueWrapper>} The wrapper.
 */
async function meter(props) {
	const wrapper = mount(PasswordStrengthMeter, { props })
	await flush()
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * The text of the feedback line.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The meter.
 * @return {string} The line.
 */
function feedbackOf(wrapper) {
	return wrapper.find('.password-strength-meter__feedback').text()
}

describe('PasswordStrengthMeter feedback', () => {
	beforeEach(() => {
		fetchPolicy.mockReset()
		fetchPolicy.mockResolvedValue(null)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('says nothing and is never valid for an empty password', async () => {
		const wrapper = await meter({ password: '', minLength: 0, minScore: 0 })

		expect(feedbackOf(wrapper)).toBe('')
		const events = wrapper.emitted('strength-change')
		expect(events[events.length - 1][0]).toEqual({ isValid: false, score: 0 })
		expect(wrapper.find('.password-strength-meter__fill').attributes('style')).toContain('width: 0%')
	})

	it('names the length floor while the password is shorter', async () => {
		const wrapper = await meter({ password: 'abc', minLength: 8 })

		// The test setup's t() returns the source text without filling it in.
		expect(feedbackOf(wrapper)).toBe('At least {length} characters required')
		expect(wrapper.emitted('strength-change').at(-1)[0].isValid).toBe(false)
	})

	it("shows zxcvbn's own warning before the strength label", async () => {
		const password = 'password1234'
		const { warning } = zxcvbn(password).feedback
		expect(warning).not.toBe('')

		const wrapper = await meter({ password, minLength: 4 })

		expect(feedbackOf(wrapper)).toBe(warning)
		expect(wrapper.find('.password-strength-meter__feedback').classes()).toContain('password-strength-meter__feedback--danger')
	})

	it('labels a password without a warning by its score', async () => {
		const password = 'correct horse battery staple violet'
		const result = zxcvbn(password)
		expect(result.feedback.warning).toBe('')
		expect(result.score).toBe(4)

		const wrapper = await meter({ password, minLength: 4 })

		expect(feedbackOf(wrapper)).toBe('Very strong')
		expect(wrapper.find('.password-strength-meter__fill').classes()).toContain('password-strength-meter__fill--success')
	})

	it('re-judges a changed password only after the 300ms debounce', async () => {
		const wrapper = await meter({ password: 'abc', minLength: 4, minScore: 0 })
		vi.useFakeTimers()
		const before = wrapper.emitted('strength-change').length

		await wrapper.setProps({ password: 'abcd' })
		await wrapper.setProps({ password: 'correct horse battery staple violet' })
		vi.advanceTimersByTime(299)
		expect(wrapper.emitted('strength-change').length).toBe(before)

		vi.advanceTimersByTime(1)
		const events = wrapper.emitted('strength-change')
		expect(events.length).toBe(before + 1)
		expect(events[events.length - 1][0]).toEqual({ isValid: true, score: 4 })
	})
})
