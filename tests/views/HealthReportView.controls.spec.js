/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/views/HealthReportView.vue` controls as wired in the template: picking a
 * staleness threshold in the select re-runs the analysis with that threshold,
 * and ticking the breach opt-in saves it and re-runs the analysis with breach
 * checking on. Only the stores, the initial-state reader and the HTTP client
 * are faked.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = { breachCheckEnabled: true }
let healthStore
let sessionStore
let rotationStore

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, key, fallback) => (key in state ? state[key] : fallback),
}))
vi.mock('../../src/store/modules/health.js', () => ({
	useHealthStore: () => healthStore,
}))
vi.mock('../../src/store/modules/session.js', () => ({
	useSessionStore: () => sessionStore,
}))
vi.mock('../../src/store/modules/rotation.js', () => ({
	useRotationStore: () => rotationStore,
}))

const { default: HealthReportView } =
	await import('../../src/views/HealthReportView.vue')

const PICKED = { label: 'Picked', value: '90' }

/**
 * Mount the view; the select stub emits PICKED when clicked.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountView() {
	const wrapper = mount(HealthReportView, {
		global: {
			mocks: { $router: { push: vi.fn() } },
			stubs: {
				NcSelect: {
					props: ['modelValue'],
					emits: ['update:modelValue'],
					template: `<div class="select" @click="$emit('update:modelValue', ${JSON.stringify(PICKED).replace(/"/g, '\'')})" />`,
				},
				NcButton: { template: '<button><slot /></button>' },
				HealthCategory: { template: '<section />' },
				OwnershipFindings: { template: '<div />' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('HealthReportView controls', () => {
	beforeEach(() => {
		state.breachCheckEnabled = true
		healthStore = {
			status: 'ready',
			score: 50,
			error: '',
			breachStatus: 'ok',
			summary: { weakCount: 0, reusedCount: 0, staleCount: 0, breachedCount: 0, compromisedCount: 0 },
			weakFindings: [],
			reusedFindings: [],
			staleFindings: [],
			breachedFindings: [],
			compromisedFindings: [],
			analyseVault: vi.fn().mockResolvedValue(),
			registerLockReset: vi.fn(),
		}
		sessionStore = { isLocked: false }
		rotationStore = {
			flags: [],
			fetchFlags: vi.fn().mockResolvedValue(),
			flagSecrets: vi.fn().mockResolvedValue(),
		}
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { breach_check_opt_in: '0' } })
		vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('re-runs the analysis with the threshold picked in the select', async () => {
		const wrapper = await mountView()
		healthStore.analyseVault.mockClear()

		await wrapper.find('.select').trigger('click')
		await flushPromises()

		expect(wrapper.vm.stalenessOption).toEqual(PICKED)
		expect(healthStore.analyseVault).toHaveBeenCalledWith({
			stalenessThreshold: '90',
			breachEnabled: false,
		})
	})

	it('saves the ticked breach opt-in and re-runs the analysis with breach checking on', async () => {
		const wrapper = await mountView()
		healthStore.analyseVault.mockClear()
		const box = wrapper.find('[data-testid="breach-optin"] input[type="checkbox"]')

		await box.setValue(true)
		await flushPromises()

		expect(wrapper.vm.breachOptIn).toBe(true)
		expect(axios.put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
			breach_check_opt_in: '1',
		})
		expect(healthStore.analyseVault).toHaveBeenCalledWith({
			stalenessThreshold: '365',
			breachEnabled: true,
		})
	})

	it('saves an unticked opt-in as off', async () => {
		axios.get.mockResolvedValue({ data: { breach_check_opt_in: '1' } })
		const wrapper = await mountView()

		await wrapper.find('[data-testid="breach-optin"] input[type="checkbox"]').setValue(false)
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
			breach_check_opt_in: '0',
		})
	})
})
