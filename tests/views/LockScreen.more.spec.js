/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lock screen paths the other LockScreen specs leave untouched:
 *
 * - the suite check's fail-closed state (banner + "Try again" re-running
 *   the check), its best-effort extras (passkey / device-approval offers
 *   only online, failures there never reverting the decision) and the
 *   insecure-context and paused-migration banners;
 * - first-time setup: the submit gate (matching + strength-valid), the
 *   mismatch debounce / reveal-on-blur feedback, createSuite success,
 *   the two-factor refusal and generic failures;
 * - passkey unlock: success redirect and both failure messages;
 * - the alternate unlock paths reached through child components: another
 *   device's approval and organisation account recovery, plus the
 *   recovery enrolment toast after a password unlock.
 *
 * Only boundaries are mocked: the stores' network actions, the router,
 * @nextcloud/dialogs, and matchMedia / isSecureContext. The two child
 * components that carry the alternate paths are stubbed so their events
 * can be emitted directly.
 */

import { showSuccess } from '@nextcloud/dialogs'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LockScreen from '../../src/views/LockScreen.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'
import { useDeviceApprovalStore } from '../../src/store/modules/deviceApproval.js'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showWarning: vi.fn(),
	TOAST_PERMANENT_TIMEOUT: -1,
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const MISMATCH = 'The password does not match. Please try again.'
const CHECK_FAILED_TEXT = 'Could not determine whether your vault is already set up.'

/**
 * Mount the lock screen with the suite check's boundaries mocked.
 *
 * @param {object} [opts] Arrangement.
 * @param {object|null} [opts.suite] The current suite (null = first setup).
 * @param {boolean} [opts.online] Whether the offline store reports online.
 * @param {boolean} [opts.secure] window.isSecureContext.
 * @param {Function} [opts.fetchSuite] fetchSuite implementation.
 * @param {boolean} [opts.passkey] isUnlockOffered answer.
 * @param {boolean} [opts.deviceApproval] fetchStatus answer.
 * @param {object} [opts.query] $route.query.
 * @return {Promise<object>} The wrapper and the spies.
 */
async function arrange({
	suite = { id: 7, status: 'active' },
	online = true,
	secure = true,
	fetchSuite = () => Promise.resolve(),
	passkey = false,
	deviceApproval = false,
	query = {},
} = {}) {
	Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true })
	const suiteStore = useEncryptionSuiteStore()
	suiteStore.currentSuite = suite
	const fetchSuiteSpy = vi.spyOn(suiteStore, 'fetchSuite').mockImplementation(fetchSuite)
	const fetchMigration = vi.spyOn(suiteStore, 'fetchMigrationStatus').mockResolvedValue(undefined)
	const createSuite = vi.spyOn(suiteStore, 'createSuite').mockResolvedValue(undefined)
	const isUnlockOffered = vi.spyOn(usePasskeyStore(), 'isUnlockOffered').mockResolvedValue(passkey)
	const unlockWithPasskey = vi.spyOn(usePasskeyStore(), 'unlockWithPasskey').mockResolvedValue(undefined)
	const fetchStatus = vi.spyOn(useDeviceApprovalStore(), 'fetchStatus').mockResolvedValue(deviceApproval)
	const unlock = vi.spyOn(useSessionStore(), 'unlock').mockResolvedValue(undefined)
	const enrol = vi.spyOn(useAccountRecoveryStore(), 'enrolAtUnlock').mockResolvedValue('skipped')
	const offline = useOfflineStore()
	offline.online = online
	const push = vi.fn()
	const wrapper = mount(LockScreen, {
		global: {
			mocks: { $router: { push }, $route: { query, hash: '' } },
			stubs: {
				DeviceApprovalRequest: { name: 'DeviceApprovalRequest', template: '<div class="stub-dar" />' },
				ForgotPasswordRecovery: { name: 'ForgotPasswordRecovery', template: '<div class="stub-fpr" />' },
				PasswordStrengthMeter: { name: 'PasswordStrengthMeter', template: '<div class="stub-psm" />' },
			},
		},
	})
	await flush()
	return {
		wrapper,
		push,
		suiteStore,
		fetchSuiteSpy,
		fetchMigration,
		createSuite,
		isUnlockOffered,
		unlockWithPasskey,
		fetchStatus,
		unlock,
		enrol,
	}
}

/**
 * Run an async handler to completion under fake timers (the unlock hold).
 *
 * @param {Function} fn The handler to run.
 * @return {Promise<void>}
 */
async function runWithTimers(fn) {
	const done = fn()
	await vi.runAllTimersAsync()
	await done
}

describe('LockScreen — suite check', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {}, removeEventListener() {} }))
		vi.mocked(showSuccess).mockClear()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('fails closed online: banner, retry button, and no setup or unlock form', async () => {
		const { wrapper, fetchMigration } = await arrange({ fetchSuite: () => Promise.reject(new Error('500')) })

		expect(wrapper.vm.suiteCheck).toBe('failed')
		expect(wrapper.vm.isFirstSetup).toBe(false)
		expect(wrapper.text()).toContain(CHECK_FAILED_TEXT)
		expect(wrapper.text()).toContain('Try again')
		expect(wrapper.find('[data-testid="unlock-with-password"]').exists()).toBe(false)
		expect(fetchMigration).not.toHaveBeenCalled()
	})

	it('"Try again" re-runs the check and lands on the unlock form once it succeeds', async () => {
		let calls = 0
		const { wrapper, fetchSuiteSpy } = await arrange({
			fetchSuite: () => (++calls === 1 ? Promise.reject(new Error('500')) : Promise.resolve()),
		})
		expect(wrapper.vm.suiteCheck).toBe('failed')

		const retry = wrapper.findAll('button').find((b) => b.text() === 'Try again')
		await retry.trigger('click')
		await flush()

		expect(fetchSuiteSpy).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.suiteCheck).toBe('resolved')
		expect(wrapper.text()).not.toContain(CHECK_FAILED_TEXT)
		expect(wrapper.find('[data-testid="unlock-with-password"]').exists()).toBe(true)
	})

	it('offline with a failed check still renders the unlock form (no banner)', async () => {
		const { wrapper } = await arrange({ online: false, fetchSuite: () => Promise.reject(new Error('net')) })

		expect(wrapper.vm.checkFailed).toBe(true)
		expect(wrapper.text()).not.toContain(CHECK_FAILED_TEXT)
		expect(wrapper.find('[data-testid="unlock-with-password"]').exists()).toBe(true)
	})

	it('online: offers passkey and device approval from the stores\' answers', async () => {
		const { wrapper, isUnlockOffered, fetchStatus } = await arrange({ passkey: true, deviceApproval: true })

		expect(isUnlockOffered).toHaveBeenCalledTimes(1)
		expect(fetchStatus).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.passkeyOffered).toBe(true)
		expect(wrapper.vm.deviceApprovalOffered).toBe(true)
		expect(wrapper.find('[data-testid="unlock-with-passkey"]').exists()).toBe(true)
		expect(wrapper.find('.stub-dar').exists()).toBe(true)
	})

	it('offline: never asks for passkey or device-approval offers', async () => {
		const { wrapper, isUnlockOffered, fetchStatus } = await arrange({ online: false, passkey: true })

		expect(isUnlockOffered).not.toHaveBeenCalled()
		expect(fetchStatus).not.toHaveBeenCalled()
		expect(wrapper.vm.passkeyOffered).toBe(false)
		expect(wrapper.find('.stub-fpr').exists()).toBe(false)
	})

	it('a failing extra (migration status) keeps the resolved decision', async () => {
		const { wrapper, fetchMigration, isUnlockOffered } = await arrange({ passkey: true })
		expect(wrapper.vm.passkeyOffered).toBe(true)

		// Re-run the check with the extra failing: the decision stays answered.
		fetchMigration.mockRejectedValue(new Error('boom'))
		await wrapper.vm.checkSuite()

		expect(wrapper.vm.suiteCheck).toBe('resolved')
		expect(isUnlockOffered).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="unlock-with-password"]').exists()).toBe(true)
	})

	it('insecure context: the HTTPS banner and no form at all', async () => {
		const { wrapper } = await arrange({ secure: false })

		expect(wrapper.text()).toContain('Keepiq requires a secure connection (HTTPS)')
		expect(wrapper.find('[data-testid="unlock-with-password"]').exists()).toBe(false)
	})

	it('a paused key rotation shows the read-only warning', async () => {
		const { wrapper, suiteStore } = await arrange()
		suiteStore.migrationStatus = { status: 'in_progress', id: 3 }
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.hasPausedMigration).toBe(true)
		expect(wrapper.text()).toContain('Key rotation is unfinished and your vault is read-only.')
	})
})

describe('LockScreen — first-time setup', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {}, removeEventListener() {} }))
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('renders the setup form when the server confirms no suite', async () => {
		const { wrapper } = await arrange({ suite: null })

		expect(wrapper.vm.isFirstSetup).toBe(true)
		expect(wrapper.text()).toContain('Set up vault')
		expect(wrapper.find('[data-testid="unlock-with-password"]').exists()).toBe(false)
	})

	it('the submit gate needs matching passwords AND a valid strength', async () => {
		const { wrapper } = await arrange({ suite: null })
		wrapper.vm.masterPassword = 'Correct-Horse-1'
		wrapper.vm.confirmPassword = 'Correct-Horse-1'
		expect(wrapper.vm.canSubmitSetup).toBe(false)

		wrapper.vm.onStrengthChange({ isValid: true })
		expect(wrapper.vm.strengthValid).toBe(true)
		expect(wrapper.vm.canSubmitSetup).toBe(true)

		wrapper.vm.confirmPassword = 'Correct-Horse-2'
		expect(wrapper.vm.canSubmitSetup).toBe(false)
	})

	it('the strength meter event reaches the gate through the template', async () => {
		const { wrapper } = await arrange({ suite: null })
		wrapper.vm.masterPassword = 'abc'
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'PasswordStrengthMeter' }).vm.$emit('strengthChange', { isValid: true })
		expect(wrapper.vm.strengthValid).toBe(true)
	})

	it('a mismatch only shows once typing has paused for a second', async () => {
		const { wrapper } = await arrange({ suite: null })
		vi.useFakeTimers()
		wrapper.vm.masterPassword = 'aaaa'
		wrapper.vm.confirmPassword = 'bbbb'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.passwordsMismatch).toBe(true)
		expect(wrapper.vm.showMismatch).toBe(false)
		expect(wrapper.vm.confirmHelperText).toBe('')

		vi.advanceTimersByTime(999)
		expect(wrapper.vm.showMismatch).toBe(false)
		vi.advanceTimersByTime(1)
		expect(wrapper.vm.showMismatch).toBe(true)
		expect(wrapper.vm.confirmHelperText).toBe(MISMATCH)

		// A keystroke hides it again.
		wrapper.vm.confirmPassword = 'aaa'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.mismatchSettled).toBe(false)
		expect(wrapper.vm.showMismatch).toBe(false)
	})

	it('leaving a field reveals the mismatch immediately', async () => {
		const { wrapper } = await arrange({ suite: null })
		vi.useFakeTimers()
		wrapper.vm.masterPassword = 'aaaa'
		wrapper.vm.confirmPassword = 'bbbb'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showMismatch).toBe(false)

		wrapper.vm.revealMismatch()
		expect(wrapper.vm.showMismatch).toBe(true)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.lock-screen__submit').attributes('title')).toBe(MISMATCH)
	})

	it('blurring a setup field reveals the mismatch; Enter in it submits setup', async () => {
		const { wrapper, createSuite } = await arrange({ suite: null })
		wrapper.vm.masterPassword = 'Correct-Horse-1'
		wrapper.vm.confirmPassword = 'Correct-Horse-2'
		await wrapper.vm.$nextTick()
		const inputs = wrapper.findAllComponents({ name: 'NcPasswordField' })
		expect(inputs).toHaveLength(2)

		await inputs[0].trigger('blur')
		expect(wrapper.vm.showMismatch).toBe(true)

		wrapper.vm.confirmPassword = 'Correct-Horse-1'
		wrapper.vm.onStrengthChange({ isValid: true })
		await wrapper.vm.$nextTick()
		await inputs[1].trigger('blur')
		await inputs[1].trigger('keyup', { key: 'Enter' })
		await flush()

		expect(createSuite).toHaveBeenCalledWith('Correct-Horse-1')
	})

	it('Enter in the unlock field runs the password unlock', async () => {
		const { wrapper, unlock, push } = await arrange({ suite: { id: 7, status: 'active' } })
		wrapper.vm.masterPassword = 'pw-enter'
		await wrapper.vm.$nextTick()
		vi.useFakeTimers()
		await wrapper.findComponent({ name: 'NcPasswordField' }).trigger('keyup', { key: 'Enter' })
		await vi.runAllTimersAsync()

		expect(unlock).toHaveBeenCalledWith('pw-enter')
		expect(push).toHaveBeenCalledWith('/')
	})

	it('matching passwords never produce mismatch feedback, even when settled', async () => {
		const { wrapper } = await arrange({ suite: null })
		wrapper.vm.masterPassword = 'same'
		wrapper.vm.confirmPassword = 'same'
		wrapper.vm.revealMismatch()

		expect(wrapper.vm.showMismatch).toBe(false)
		expect(wrapper.vm.confirmHelperText).toBe('')
	})

	it('does nothing when the gate is closed', async () => {
		const { wrapper, createSuite, push } = await arrange({ suite: null })
		wrapper.vm.masterPassword = 'x'
		wrapper.vm.confirmPassword = 'y'
		await wrapper.vm.handleSetup()

		expect(createSuite).not.toHaveBeenCalled()
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.masterPassword).toBe('x')
	})

	it('creates the suite with the password, goes to the root, clears both fields', async () => {
		const { wrapper, createSuite, push } = await arrange({ suite: null })
		wrapper.vm.masterPassword = 'Correct-Horse-1'
		wrapper.vm.confirmPassword = 'Correct-Horse-1'
		wrapper.vm.onStrengthChange({ isValid: true })
		await wrapper.vm.handleSetup()

		expect(createSuite).toHaveBeenCalledWith('Correct-Horse-1')
		expect(push).toHaveBeenCalledWith('/')
		expect(wrapper.vm.masterPassword).toBe('')
		expect(wrapper.vm.confirmPassword).toBe('')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe(null)
	})

	it('a two-factor refusal flags the policy with no error text', async () => {
		const { wrapper, createSuite, push } = await arrange({ suite: null })
		createSuite.mockRejectedValue({ message: 'x', response: { data: { code: 'two_factor_required' } } })
		wrapper.vm.masterPassword = 'pw'
		wrapper.vm.confirmPassword = 'pw'
		wrapper.vm.onStrengthChange({ isValid: true })
		await wrapper.vm.handleSetup()

		expect(wrapper.vm.twoFactorRefused).toBe(true)
		expect(wrapper.vm.twoFactorRequired).toBe(true)
		expect(wrapper.vm.error).toBe(null)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('a failure shows the error message inline under the confirm field', async () => {
		const { wrapper, createSuite } = await arrange({ suite: null })
		createSuite.mockRejectedValue(new Error('Suite already exists'))
		wrapper.vm.masterPassword = 'pw'
		wrapper.vm.confirmPassword = 'pw'
		wrapper.vm.onStrengthChange({ isValid: true })
		await wrapper.vm.handleSetup()

		expect(wrapper.vm.error).toBe('Suite already exists')
		expect(wrapper.vm.confirmHelperText).toBe('Suite already exists')
		expect(wrapper.vm.liveAlert).toBe('Suite already exists')
	})

	it('a failure without a message falls back to "Setup failed"', async () => {
		const { wrapper, createSuite } = await arrange({ suite: null })
		createSuite.mockRejectedValue({})
		wrapper.vm.masterPassword = 'pw'
		wrapper.vm.confirmPassword = 'pw'
		wrapper.vm.onStrengthChange({ isValid: true })
		await wrapper.vm.handleSetup()

		expect(wrapper.vm.error).toBe('Setup failed')
	})
})

describe('LockScreen — passkey and alternate unlock paths', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {}, removeEventListener() {} }))
		vi.mocked(showSuccess).mockClear()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('passkey unlock: unlocks via the store and goes to the return URL', async () => {
		const { wrapper, unlockWithPasskey, push } = await arrange({ passkey: true, query: { returnUrl: '/vault/9' } })
		vi.useFakeTimers()
		await runWithTimers(() => wrapper.vm.handlePasskeyUnlock())

		expect(unlockWithPasskey).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.unlocked).toBe(true)
		expect(push).toHaveBeenCalledWith('/vault/9')
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('passkey unlock via the button: no return URL goes to the root', async () => {
		const { wrapper, unlockWithPasskey, push } = await arrange({ passkey: true })
		vi.useFakeTimers()
		await wrapper.find('[data-testid="unlock-with-passkey"]').trigger('click')
		await vi.runAllTimersAsync()

		expect(unlockWithPasskey).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith('/')
	})

	it('passkey failure: shows the store\'s message and flashes the rejection', async () => {
		const { wrapper, unlockWithPasskey, push } = await arrange({ passkey: true })
		unlockWithPasskey.mockRejectedValue(new Error('The operation was aborted'))
		vi.useFakeTimers()
		await wrapper.vm.handlePasskeyUnlock()

		expect(wrapper.vm.error).toBe('The operation was aborted')
		expect(wrapper.vm.unlockRejected).toBe(true)
		expect(wrapper.vm.rejectionSeq).toBe(1)
		expect(wrapper.vm.loading).toBe(false)
		expect(push).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1100)
		expect(wrapper.vm.unlockRejected).toBe(false)
		expect(wrapper.vm.error).toBe('The operation was aborted')
	})

	it('passkey failure without a message: the master-password fallback text', async () => {
		const { wrapper, unlockWithPasskey } = await arrange({ passkey: true })
		unlockWithPasskey.mockRejectedValue(undefined)
		await wrapper.vm.handlePasskeyUnlock()

		expect(wrapper.vm.error).toBe('Passkey unlock failed — use your master password')
	})

	it('another device\'s approval continues to the return URL', async () => {
		const { wrapper, push } = await arrange({ deviceApproval: true, query: { returnUrl: '/s/1' } })
		vi.useFakeTimers()
		wrapper.findComponent({ name: 'DeviceApprovalRequest' }).vm.$emit('unlocked')
		await vi.runAllTimersAsync()

		expect(wrapper.vm.unlocked).toBe(true)
		expect(push).toHaveBeenCalledWith('/s/1')
	})

	it('recovery: names the officer in a permanent toast, then enters the vault', async () => {
		const { wrapper, push } = await arrange()
		vi.useFakeTimers()
		await runWithTimers(() => wrapper.vm.onRecovered('Alice'))

		expect(showSuccess).toHaveBeenCalledWith(
			'Recovered with help from {officer}. Rotate your vault key now in Settings, Security: "My master password was compromised".',
			{ timeout: -1 },
		)
		expect(push).toHaveBeenCalledWith('/')
	})

	it('recovery is reachable from the forgot-password component', async () => {
		const { wrapper, push } = await arrange({ query: { returnUrl: '/after' } })
		vi.useFakeTimers()
		wrapper.findComponent({ name: 'ForgotPasswordRecovery' }).vm.$emit('recovered', '')
		await vi.runAllTimersAsync()

		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith('/after')
	})

	it('a password unlock that enrols in recovery shows the enrolment toast', async () => {
		const { wrapper, enrol, push } = await arrange()
		enrol.mockResolvedValue('enrolled')
		useAccountRecoveryStore().status = { key: { fingerprint: 'AB:CD' } }
		wrapper.vm.masterPassword = 'pw-9'
		vi.useFakeTimers()
		await runWithTimers(() => wrapper.vm.handleUnlock())

		expect(enrol).toHaveBeenCalledWith('pw-9')
		expect(showSuccess).toHaveBeenCalledWith(
			'You are enrolled in account recovery. Recovery key fingerprint: {fingerprint}',
		)
		expect(push).toHaveBeenCalledWith('/')
	})

	it('a skipped enrolment shows no toast', async () => {
		const { wrapper, enrol, push } = await arrange()
		wrapper.vm.masterPassword = 'pw-9'
		vi.useFakeTimers()
		await runWithTimers(() => wrapper.vm.handleUnlock())

		expect(enrol).toHaveBeenCalledWith('pw-9')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(push).toHaveBeenCalledWith('/')
	})

	it('unmounting mid-hold cancels the redirect', async () => {
		const { wrapper, push } = await arrange()
		vi.useFakeTimers()
		wrapper.vm.onApprovedUnlock()
		wrapper.unmount()
		await vi.runAllTimersAsync()

		expect(push).not.toHaveBeenCalled()
	})
})
