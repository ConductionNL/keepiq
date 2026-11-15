/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/CopyButton.vue`, beyond the basic copy /
 * resolve / auto-clear / fallback paths pinned in CopyButton.spec.js.
 *
 * Covers: the pointerdown pre-warm (the resolver runs once inside the tap
 * gesture and the click reuses its value; a failed pre-warm leaves the click
 * to resolve again; a use-only button never resolves), the use-only refusal
 * (nothing rendered, a programmatic copy writes nothing and emits nothing),
 * the transient "copied" indicator, a second copy replacing the pending
 * auto-clear timer, and unmount cancelling the pending auto-clear.
 *
 * Only `setTimeout` / `clearTimeout` are faked, so no assertion depends on
 * the wall clock.
 *
 * @spec openspec/specs/mobile-pwa/spec.md#scenario-copy-inside-the-tap-gesture-honest-failure-without-a-secure-context
 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CopyButton from '../../src/components/CopyButton.vue'

describe('CopyButton pre-warm, use-only and timers', () => {
	let writeTextSpy

	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		writeTextSpy = vi.fn().mockResolvedValue()
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: writeTextSpy },
			configurable: true,
		})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	/** Let pending promise chains settle without advancing the fake clock. */
	const settle = () => vi.advanceTimersByTimeAsync(0)

	it('uses the translated default label as aria-label', () => {
		const wrapper = mount(CopyButton, { props: { value: 'v' } })
		expect(wrapper.find('button').attributes('aria-label')).toBe(
			'Copy to clipboard',
		)
	})

	it('pre-resolves on pointerdown and the click reuses that value', async () => {
		const resolve = vi.fn().mockResolvedValue('value-1')
		const wrapper = mount(CopyButton, { props: { resolve } })

		await wrapper.find('button').trigger('pointerdown')
		await settle()
		expect(resolve).toHaveBeenCalledOnce()
		expect(wrapper.vm.prewarmed).toBe('value-1')

		// A second pointerdown does not resolve again.
		await wrapper.find('button').trigger('pointerdown')
		await settle()
		expect(resolve).toHaveBeenCalledOnce()

		await wrapper.find('button').trigger('click')
		await settle()
		expect(resolve).toHaveBeenCalledOnce()
		expect(writeTextSpy).toHaveBeenCalledWith('value-1')
		expect(wrapper.emitted('copied')).toHaveLength(1)
	})

	it('leaves prewarmed null when the pre-warm fails, so the click resolves again', async () => {
		const resolve = vi
			.fn()
			.mockRejectedValueOnce(new Error('locked'))
			.mockResolvedValueOnce('value-2')
		const wrapper = mount(CopyButton, { props: { resolve } })

		await wrapper.find('button').trigger('pointerdown')
		await settle()
		expect(wrapper.vm.prewarmed).toBeNull()

		await wrapper.find('button').trigger('click')
		await settle()
		expect(resolve).toHaveBeenCalledTimes(2)
		expect(writeTextSpy).toHaveBeenCalledWith('value-2')
	})

	it('a use-only button renders nothing and refuses programmatic copy / pre-warm', async () => {
		const resolve = vi.fn().mockResolvedValue('value-3')
		const wrapper = mount(CopyButton, { props: { resolve, useOnly: true } })

		expect(wrapper.find('button').exists()).toBe(false)

		await wrapper.vm.prewarm()
		await wrapper.vm.onCopy()

		expect(resolve).not.toHaveBeenCalled()
		expect(writeTextSpy).not.toHaveBeenCalled()
		expect(wrapper.vm.prewarmed).toBeNull()
		expect(wrapper.emitted('copied')).toBeUndefined()
	})

	it('shows the copied indicator for 1.5s after a copy', async () => {
		const wrapper = mount(CopyButton, { props: { value: 'v', clearAfter: 0 } })
		expect(wrapper.vm.copied).toBe(false)

		await wrapper.find('button').trigger('click')
		await settle()
		expect(wrapper.vm.copied).toBe(true)

		await vi.advanceTimersByTimeAsync(1499)
		expect(wrapper.vm.copied).toBe(true)
		await vi.advanceTimersByTimeAsync(1)
		expect(wrapper.vm.copied).toBe(false)
	})

	it('a second copy replaces the pending auto-clear timer', async () => {
		const wrapper = mount(CopyButton, { props: { value: 'v', clearAfter: 10 } })

		await wrapper.find('button').trigger('click')
		await settle()
		await vi.advanceTimersByTimeAsync(6000)
		await wrapper.find('button').trigger('click')
		await settle()

		// 10s after the FIRST copy: the first timer was cancelled.
		await vi.advanceTimersByTimeAsync(4000)
		expect(writeTextSpy.mock.calls.map((c) => c[0])).toEqual(['v', 'v'])

		// 10s after the SECOND copy: exactly one clear.
		await vi.advanceTimersByTimeAsync(6000)
		expect(writeTextSpy.mock.calls.map((c) => c[0])).toEqual(['v', 'v', ''])
	})

	it('cancels the pending auto-clear on unmount', async () => {
		const wrapper = mount(CopyButton, { props: { value: 'v', clearAfter: 5 } })

		await wrapper.find('button').trigger('click')
		await settle()
		wrapper.unmount()

		await vi.advanceTimersByTimeAsync(10000)
		expect(writeTextSpy.mock.calls.map((c) => c[0])).toEqual(['v'])
	})
})
