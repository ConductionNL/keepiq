/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the re-prompt guard of `src/components/PasswordField.vue`
 * and the CopyButton it renders (crypto-item-reprompt).
 *
 * A flagged item passes its master password re-prompt as `guard`. Pins:
 * every reveal awaits the guard first, a rejected guard reveals nothing and
 * never runs the resolver, hiding never asks, and the copy button shares the
 * same guard so a rejected re-prompt copies nothing.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CopyButton from '../../src/components/CopyButton.vue'
import PasswordField from '../../src/components/PasswordField.vue'

describe('PasswordField re-prompt guard', () => {
	let writeText

	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('reveals after the guard resolves, and asks again on every reveal but not on hide', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const guard = vi.fn().mockResolvedValue()
		const wrapper = mount(PasswordField, { props: { resolve, guard } })

		await wrapper.vm.toggle()
		expect(guard).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.revealed).toBe(true)
		expect(wrapper.vm.displayValue).toBe('hunter2')

		await wrapper.vm.toggle()
		expect(guard).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.revealed).toBe(false)

		await wrapper.vm.toggle()
		expect(guard).toHaveBeenCalledTimes(2)
		expect(resolve).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.revealed).toBe(true)
	})

	it('reveals nothing and never decrypts when the guard is rejected', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const guard = vi.fn().mockRejectedValue(new Error('closed'))
		const wrapper = mount(PasswordField, { props: { resolve, guard } })

		await wrapper.vm.toggle()

		expect(guard).toHaveBeenCalledTimes(1)
		expect(resolve).not.toHaveBeenCalled()
		expect(wrapper.vm.revealed).toBe(false)
		expect(wrapper.vm.displayValue).toBe('••••••••••')
	})

	it('passes the guard to its copy button', () => {
		const guard = vi.fn()
		const wrapper = mount(PasswordField, { props: { resolve: vi.fn(), guard } })

		expect(wrapper.findComponent(CopyButton).props('guard')).toBe(guard)
	})
})

describe('CopyButton re-prompt guard', () => {
	let writeText

	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('copies nothing when the guard is rejected', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const guard = vi.fn().mockRejectedValue(new Error('closed'))
		const wrapper = mount(CopyButton, { props: { resolve, guard } })

		await wrapper.vm.onCopy()

		expect(guard).toHaveBeenCalledTimes(1)
		expect(resolve).not.toHaveBeenCalled()
		expect(writeText).not.toHaveBeenCalled()
		expect(wrapper.emitted('copied')).toBeUndefined()
		expect(wrapper.vm.copied).toBe(false)
	})

	it('copies the resolved value after the guard resolves', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const guard = vi.fn().mockResolvedValue()
		const wrapper = mount(CopyButton, {
			props: { resolve, guard, clearAfter: 0 },
		})

		await wrapper.vm.onCopy()

		expect(guard).toHaveBeenCalledTimes(1)
		expect(writeText).toHaveBeenCalledWith('hunter2')
		expect(wrapper.emitted('copied')).toHaveLength(1)
	})
})
