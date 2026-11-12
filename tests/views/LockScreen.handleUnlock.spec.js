/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of the master-password unlock: which store unlocks, where
 * it goes, and what a refusal shows, for every path.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LockScreen from '../../src/views/LockScreen.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const WRONG = 'Wrong master password or decryption failed'

async function arrange({ online = true, query = {} } = {}) {
	const suiteStore = useEncryptionSuiteStore()
	suiteStore.currentSuite = { id: 7, status: 'active' }
	vi.spyOn(suiteStore, 'fetchSuite').mockResolvedValue(undefined)
	vi.spyOn(suiteStore, 'fetchMigrationStatus').mockResolvedValue(undefined)
	vi.spyOn(usePasskeyStore(), 'isUnlockOffered').mockResolvedValue(false)
	const session = useSessionStore()
	const unlock = vi.spyOn(session, 'unlock').mockResolvedValue(undefined)
	const enrol = vi.spyOn(useAccountRecoveryStore(), 'enrolAtUnlock').mockResolvedValue('skipped')
	const offline = useOfflineStore()
	offline.online = online
	const unlockOffline = vi.spyOn(offline, 'unlockOffline').mockResolvedValue(undefined)
	const evict = vi.spyOn(offline, 'evict').mockResolvedValue(undefined)
	const push = vi.fn()
	const wrapper = mount(LockScreen, {
		global: { mocks: { $router: { push }, $route: { query, hash: '' } } },
	})
	await flush()
	vi.useFakeTimers()
	wrapper.vm.masterPassword = 'pw-1'
	return { wrapper, push, unlock, enrol, unlockOffline, evict }
}

async function unlockNow(wrapper) {
	const done = wrapper.vm.handleUnlock()
	await vi.runAllTimersAsync()
	await done
}

describe('LockScreen handleUnlock', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.isSecureContext = true
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {}, removeEventListener() {} }))
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('online: unlocks the session, enrols, and goes to the return URL', async () => {
		const { wrapper, push, unlock, enrol, unlockOffline } = await arrange({ query: { returnUrl: '/vault/7' } })
		await unlockNow(wrapper)

		expect(unlock).toHaveBeenCalledWith('pw-1')
		expect(enrol).toHaveBeenCalledWith('pw-1')
		expect(unlockOffline).not.toHaveBeenCalled()
		expect(push).toHaveBeenCalledWith('/vault/7')
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.masterPassword).toBe('')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('offline: unlocks the snapshot only, and goes to the root', async () => {
		const { wrapper, push, unlock, unlockOffline } = await arrange({ online: false })
		await unlockNow(wrapper)

		expect(unlock).not.toHaveBeenCalled()
		expect(unlockOffline).toHaveBeenCalledWith('pw-1')
		expect(push).toHaveBeenCalledWith('/')
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('online but unreachable: falls back to the snapshot with the same password', async () => {
		const { wrapper, push, unlock, unlockOffline } = await arrange({ query: { returnUrl: '/x' } })
		unlock.mockRejectedValue({ message: 'Network Error' })
		await unlockNow(wrapper)

		expect(unlockOffline).toHaveBeenCalledWith('pw-1')
		expect(push).toHaveBeenCalledWith('/x')
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('online, unreachable and the snapshot refuses too: the wrong-password text', async () => {
		const { wrapper, push, unlock, unlockOffline } = await arrange()
		unlock.mockRejectedValue({ code: 'ERR_NETWORK' })
		unlockOffline.mockRejectedValue(new Error('bad'))
		await unlockNow(wrapper)

		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(WRONG)
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('the two-factor policy: no error text, the snapshot is dropped', async () => {
		const { wrapper, push, unlock, evict, unlockOffline } = await arrange()
		unlock.mockRejectedValue({ code: 'two_factor_required' })
		await unlockNow(wrapper)

		expect(wrapper.vm.twoFactorRefused).toBe(true)
		expect(evict).toHaveBeenCalledTimes(1)
		expect(unlockOffline).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(null)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('a refused password: the wrong-password text, no fallback, no redirect', async () => {
		const { wrapper, push, unlock, unlockOffline, evict } = await arrange()
		unlock.mockRejectedValue(new Error('OperationError'))
		await unlockNow(wrapper)

		expect(unlockOffline).not.toHaveBeenCalled()
		expect(evict).not.toHaveBeenCalled()
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(WRONG)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('offline and refused: the wrong-password text, never a second try', async () => {
		const { wrapper, push, unlockOffline } = await arrange({ online: false })
		unlockOffline.mockRejectedValue({ message: 'Network Error' })
		await unlockNow(wrapper)

		expect(unlockOffline).toHaveBeenCalledTimes(1)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(WRONG)
	})
})
