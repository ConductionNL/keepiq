/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The one-time code of a flagged item waits behind the master password
 * re-prompt: nothing is computed until the guard lets it through, a
 * cancelled prompt shows nothing, and a new seed or a vault lock asks again.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.2
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TotpDisplay from '../../src/components/TotpDisplay.vue'
import { useSessionStore } from '../../src/store/modules/session.js'

const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'

/**
 * Wait until the async code generation settles.
 *
 * @param {object} vm The component instance.
 * @return {Promise<void>}
 */
async function settle(vm) {
	for (let i = 0; i < 50; i++) {
		if (vm.code !== '') {
			break
		}
		await new Promise((resolve) => setTimeout(resolve, 5))
	}
	await vm.$nextTick()
}

describe('TotpDisplay behind the re-prompt', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useSessionStore().cryptoKey = { fake: true }
	})

	it('computes nothing and shows only the show button until the guard lets it through', async () => {
		const guard = vi.fn().mockResolvedValue(undefined)
		const wrapper = mount(TotpDisplay, { props: { seed: SECRET, guard } })
		await settle(wrapper.vm)

		expect(wrapper.vm.code).toBe('')
		expect(wrapper.vm.params).toBe(null)
		expect(wrapper.find('[data-testid="totp-code"]').exists()).toBe(false)
		expect(guard).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="totp-show"]').trigger('click')
		await settle(wrapper.vm)

		expect(guard).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="totp-show"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="totp-code"]').text().replace(/\s/g, '')).toMatch(/^\d{6}$/)
		wrapper.unmount()
	})

	it('shows nothing when the prompt is cancelled', async () => {
		const guard = vi.fn().mockRejectedValue(new Error('cancelled'))
		const wrapper = mount(TotpDisplay, { props: { seed: SECRET, guard } })

		await wrapper.vm.unlock()
		await settle(wrapper.vm)

		expect(guard).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.code).toBe('')
		expect(wrapper.find('[data-testid="totp-show"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('asks again for another seed and after the vault locks', async () => {
		const guard = vi.fn().mockResolvedValue(undefined)
		const wrapper = mount(TotpDisplay, { props: { seed: SECRET, guard } })
		await wrapper.vm.unlock()
		await settle(wrapper.vm)
		expect(wrapper.vm.code).not.toBe('')

		await wrapper.setProps({ seed: 'JBSWY3DPEHPK3PXP' })
		await settle(wrapper.vm)
		expect(wrapper.vm.code).toBe('')
		expect(wrapper.find('[data-testid="totp-show"]').exists()).toBe(true)

		await wrapper.vm.unlock()
		await settle(wrapper.vm)
		expect(wrapper.vm.code).not.toBe('')
		wrapper.vm.lock()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.code).toBe('')
		expect(wrapper.find('[data-testid="totp-show"]').exists()).toBe(true)
		expect(guard).toHaveBeenCalledTimes(2)
		wrapper.unmount()
	})

	it('hands the guard to the copy button, and shows the code at once without a guard', async () => {
		const guard = vi.fn().mockResolvedValue(undefined)
		const guarded = mount(TotpDisplay, { props: { seed: SECRET, guard } })
		await guarded.vm.unlock()
		await settle(guarded.vm)
		expect(guarded.findComponent({ name: 'CopyButton' }).props('guard')).toBe(guard)
		guarded.unmount()

		const open = mount(TotpDisplay, { props: { seed: SECRET } })
		await settle(open.vm)
		expect(open.find('[data-testid="totp-show"]').exists()).toBe(false)
		expect(open.vm.code).toMatch(/^\d{6}$/)
		open.unmount()
	})
})
