/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The health report's preference loading, persistence, re-analysis and
 * rotation flagging. Stores are faked on the component instance; only the
 * HTTP client is spied on.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HealthReportView from '../../src/views/HealthReportView.vue'

const { computed, methods, watch } = HealthReportView
const options = computed.stalenessOptions.call({})

/**
 * A fake component instance with the view's methods bound to it.
 *
 * @param {object} overrides Fields to set on the instance.
 * @return {object} The instance.
 */
function makeVm(overrides = {}) {
	const vm = {
		store: {
			analyseVault: vi.fn().mockResolvedValue(),
			registerLockReset: vi.fn(),
			breachedFindings: [],
		},
		session: { isLocked: false },
		rotation: {
			flags: [],
			fetchFlags: vi.fn().mockResolvedValue(),
			flagSecrets: vi.fn().mockResolvedValue(),
		},
		breachGateOn: false,
		breachOptIn: false,
		stalenessOption: options[2],
		stalenessOptions: options,
		breachActive: false,
		locked: false,
		...overrides,
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('HealthReportView', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('computed', () => {
		it('offers 90, 180, 365 days and never as thresholds', () => {
			expect(options.map((o) => o.value)).toEqual(['90', '180', '365', 'never'])
		})

		it('reports locked from the session store', () => {
			expect(computed.locked.call({ session: { isLocked: true } })).toBe(true)
			expect(computed.locked.call({ session: { isLocked: false } })).toBe(false)
		})

		it('activates breach checking only when the admin gate and the opt-in are both on', () => {
			const active = (breachGateOn, breachOptIn) =>
				computed.breachActive.call({ breachGateOn, breachOptIn })
			expect(active(true, true)).toBe(true)
			expect(active(true, false)).toBe(false)
			expect(active(false, true)).toBe(false)
		})

		it('maps rotation flags to findings, falling back to the id for a missing name', () => {
			const rotation = {
				flags: [
					{ secretId: 's1', secretName: 'Mail' },
					{ secretId: 's2', secretName: '' },
				],
			}
			expect(computed.rotationFindings.call({ rotation })).toEqual([
				{ id: 's1', name: 'Mail' },
				{ id: 's2', name: 's2' },
			])
		})
	})

	describe('loadPrefs', () => {
		it('selects the stored threshold and reads a "1" opt-in as true', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({
				data: { health_staleness_days: 90, breach_check_opt_in: '1' },
			})
			const vm = makeVm()

			await vm.loadPrefs()

			expect(get).toHaveBeenCalledWith('/apps/keepiq/api/settings/user')
			expect(vm.stalenessOption).toBe(options[0])
			expect(vm.breachOptIn).toBe(true)
		})

		it('reads a boolean true opt-in as true and anything else as false', async () => {
			vi.spyOn(axios, 'get').mockResolvedValueOnce({
				data: { breach_check_opt_in: true },
			})
			const vm = makeVm()
			await vm.loadPrefs()
			expect(vm.breachOptIn).toBe(true)

			vi.spyOn(axios, 'get').mockResolvedValueOnce({
				data: { breach_check_opt_in: '0' },
			})
			await vm.loadPrefs()
			expect(vm.breachOptIn).toBe(false)
		})

		it('keeps the current threshold when the stored one is not an option', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { health_staleness_days: '42' },
			})
			const vm = makeVm({ stalenessOption: options[1] })

			await vm.loadPrefs()

			expect(vm.stalenessOption).toBe(options[1])
		})

		it('keeps the defaults when the request fails', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const vm = makeVm()

			await vm.loadPrefs()

			expect(vm.stalenessOption).toBe(options[2])
			expect(vm.breachOptIn).toBe(false)
			expect(warn).toHaveBeenCalledTimes(1)
		})
	})

	describe('reanalyse', () => {
		it('passes the selected threshold and the breach state to the store', async () => {
			const vm = makeVm({ stalenessOption: options[1], breachActive: true })

			await vm.reanalyse()

			expect(vm.store.analyseVault).toHaveBeenCalledWith({
				stalenessThreshold: '180',
				breachEnabled: true,
			})
		})

		it('defaults the threshold to 365 when nothing is selected', async () => {
			const vm = makeVm({ stalenessOption: null })

			await vm.reanalyse()

			expect(vm.store.analyseVault).toHaveBeenCalledWith({
				stalenessThreshold: '365',
				breachEnabled: false,
			})
		})
	})

	describe('flagBreached', () => {
		it('sends only the breached secret ids to the rotation store', async () => {
			const vm = makeVm()
			vm.store.breachedFindings = [
				{ id: 'a', verdict: 'breached', count: 3 },
				{ id: 'b', verdict: 'breached', count: 1 },
			]

			await vm.flagBreached()

			expect(vm.rotation.flagSecrets).toHaveBeenCalledWith(['a', 'b'])
		})

		it('swallows a flagging failure with a warning', async () => {
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const vm = makeVm()
			vm.rotation.flagSecrets = vi.fn().mockRejectedValue(new Error('500'))

			await expect(vm.flagBreached()).resolves.toBeUndefined()
			expect(warn).toHaveBeenCalledTimes(1)
		})
	})

	describe('onBreachToggle', () => {
		it('persists the opt-in as "1" and re-analyses', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({})
			const vm = makeVm({ breachOptIn: true })

			await vm.onBreachToggle()

			expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
				breach_check_opt_in: '1',
			})
			expect(vm.store.analyseVault).toHaveBeenCalledTimes(1)
		})

		it('persists an opt-out as "0" and still re-analyses when saving fails', async () => {
			const put = vi.spyOn(axios, 'put').mockRejectedValue(new Error('500'))
			vi.spyOn(console, 'warn').mockImplementation(() => {})
			const vm = makeVm({ breachOptIn: false })

			await vm.onBreachToggle()

			expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
				breach_check_opt_in: '0',
			})
			expect(vm.store.analyseVault).toHaveBeenCalledTimes(1)
		})
	})

	describe('persistStaleness', () => {
		it('saves the selected threshold', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({})
			const vm = makeVm({ stalenessOption: options[3] })

			await vm.persistStaleness()

			expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
				health_staleness_days: 'never',
			})
		})

		it('saves 365 when nothing is selected and swallows a failure', async () => {
			const put = vi.spyOn(axios, 'put').mockRejectedValue(new Error('500'))
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const vm = makeVm({ stalenessOption: undefined })

			await expect(vm.persistStaleness()).resolves.toBeUndefined()
			expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
				health_staleness_days: '365',
			})
			expect(warn).toHaveBeenCalledTimes(1)
		})

		it('is what the threshold watcher runs', () => {
			const vm = makeVm()
			vm.persistStaleness = vi.fn()

			watch.stalenessOption.call(vm)

			expect(vm.persistStaleness).toHaveBeenCalledTimes(1)
		})
	})

	describe('created', () => {
		it('registers the lock reset, loads prefs and flags, then analyses an unlocked vault', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
			const vm = makeVm({ locked: false })

			await HealthReportView.created.call(vm)

			expect(vm.store.registerLockReset).toHaveBeenCalledTimes(1)
			expect(vm.rotation.fetchFlags).toHaveBeenCalledTimes(1)
			expect(vm.store.analyseVault).toHaveBeenCalledTimes(1)
		})

		it('does not analyse a locked vault and survives a flag-load failure', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const vm = makeVm({ locked: true })
			vm.rotation.fetchFlags = vi.fn().mockRejectedValue(new Error('500'))

			await HealthReportView.created.call(vm)

			expect(vm.store.analyseVault).not.toHaveBeenCalled()
			expect(warn).toHaveBeenCalledWith(
				'Keepiq: failed to load rotation flags',
				expect.any(Error),
			)
		})
	})
})
