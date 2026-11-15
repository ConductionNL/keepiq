/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * TotpDisplay over time: a changed seed restarts the code, the one-second
 * tick refreshes it, and a tick that finds the vault locked discards every
 * trace of the code instead of computing a new one.
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import TotpDisplay from '../../src/components/TotpDisplay.vue'
import { useSessionStore } from '../../src/store/modules/session.js'
import { generateTotp, parseOtpauth } from '../../src/totp/totp.js'

const FIRST = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
const SECOND = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'

/** A moment 5 seconds into a 30-second window, so no test crosses a boundary. */
const NOW = new Date('2026-11-13T12:00:05Z')

/**
 * Wait until the component's code equals the expected one, or give up.
 *
 * @param {object} vm The component instance.
 * @param {string} expected The code to wait for.
 * @return {Promise<void>}
 */
async function waitForCode(vm, expected) {
	for (let i = 0; i < 100 && vm.code !== expected; i++) {
		await new Promise((resolve) => setTimeout(resolve, 5))
	}
	await vm.$nextTick()
}

describe('TotpDisplay lifecycle', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
		vi.setSystemTime(NOW)
		useSessionStore().cryptoKey = { fake: true }
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('restarts with the new seed when the seed changes', async () => {
		const first = await generateTotp(parseOtpauth(FIRST))
		const second = await generateTotp(parseOtpauth(SECOND))
		expect(first).not.toBe(second)

		const wrapper = mount(TotpDisplay, { props: { seed: FIRST } })
		await waitForCode(wrapper.vm, first)
		expect(wrapper.find('[data-testid="totp-code"]').text().replace(/\s/g, '')).toBe(first)

		await wrapper.setProps({ seed: SECOND })
		await waitForCode(wrapper.vm, second)

		expect(wrapper.find('[data-testid="totp-code"]').text().replace(/\s/g, '')).toBe(second)
		expect(wrapper.find('[data-testid="totp-countdown"]').text()).toBe('25s')
		wrapper.unmount()
	})

	it('refreshes the code on the one-second tick into the next window', async () => {
		const wrapper = mount(TotpDisplay, { props: { seed: FIRST } })
		const now = await generateTotp(parseOtpauth(FIRST))
		await waitForCode(wrapper.vm, now)

		vi.setSystemTime(new Date(NOW.getTime() + 25000))
		const next = await generateTotp(parseOtpauth(FIRST))
		expect(next).not.toBe(now)
		vi.advanceTimersByTime(1000)
		await waitForCode(wrapper.vm, next)

		expect(wrapper.vm.code).toBe(next)
		// The tick itself moves the clock one more second, to :31.
		expect(wrapper.vm.remaining).toBe(29)
		wrapper.unmount()
	})

	it('discards the code when a tick finds the vault locked', async () => {
		const wrapper = mount(TotpDisplay, { props: { seed: FIRST } })
		await waitForCode(wrapper.vm, await generateTotp(parseOtpauth(FIRST)))
		expect(wrapper.vm.timer).not.toBe(null)

		useSessionStore().cryptoKey = null
		await wrapper.vm.tick()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.code).toBe('')
		expect(wrapper.vm.params).toBe(null)
		expect(wrapper.vm.timer).toBe(null)
		expect(wrapper.vm.spacedCode).toBe('')
		expect(wrapper.vm.ringOffset).toBe(0)
		expect(wrapper.find('[data-testid="totp-code"]').exists()).toBe(false)
		wrapper.unmount()
	})
})
