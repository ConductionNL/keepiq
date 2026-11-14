/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/views/HealthReportView.vue` as rendered: the locked prompt, the
 * analysing / ready / error states, the breach opt-in shown only behind the
 * admin gate, the breached category and batch-flag button only when breach
 * checking is active, the unavailable notice otherwise, and the rotation
 * category built from the open rotation flags. Only the stores, the
 * initial-state reader and the HTTP client are faked.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const state = { breachCheckEnabled: false }
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
vi.mock('../../src/store/modules/secret.js', () => ({
	useSecretStore: () => ({ updateSecret: vi.fn() }),
}))

const { default: HealthReportView } =
	await import('../../src/views/HealthReportView.vue')

const HealthCategoryStub = {
	name: 'HealthCategory',
	props: ['title', 'description', 'findings', 'testid'],
	emits: ['open'],
	render() {
		return h(
			'section',
			{ 'data-testid': this.testid },
			(this.findings || []).map((f) =>
				h('span', { class: 'finding' }, f.name),
			),
		)
	},
}

/**
 * Mount the view against the current fake stores.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountView() {
	const wrapper = mount(HealthReportView, {
		global: {
			mocks: { $router: { push: vi.fn() } },
			stubs: {
				NcSelect: { template: '<div class="select" />' },
				NcButton: {
					emits: ['click'],
					template: '<button @click="$emit(\'click\')"><slot /></button>',
				},
				HealthCategory: HealthCategoryStub,
				OwnershipFindings: { template: '<div class="ownership" />' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('HealthReportView rendered', () => {
	beforeEach(() => {
		state.breachCheckEnabled = false
		healthStore = {
			status: 'ready',
			score: 72,
			error: '',
			breachStatus: 'ok',
			summary: {
				weakCount: 1,
				reusedCount: 2,
				staleCount: 3,
				breachedCount: 4,
				compromisedCount: 5,
				insecureAddressCount: 6,
			},
			weakFindings: [{ id: 'w1', name: 'Weak one' }],
			reusedFindings: [],
			staleFindings: [],
			breachedFindings: [{ id: 'b1', name: 'Breached one' }],
			compromisedFindings: [],
			insecureAddressFindings: [],
			analyseVault: vi.fn().mockResolvedValue(),
			registerLockReset: vi.fn(),
		}
		sessionStore = { isLocked: false }
		rotationStore = {
			flags: [],
			fetchFlags: vi.fn().mockResolvedValue(),
			flagSecrets: vi.fn().mockResolvedValue(),
		}
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows only the unlock prompt and skips analysis when the vault is locked', async () => {
		sessionStore.isLocked = true

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="health-report-locked"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="health-score"]').exists()).toBe(false)
		expect(wrapper.find('.select').exists()).toBe(false)
		expect(healthStore.analyseVault).not.toHaveBeenCalled()
	})

	it('renders the score, the counts and the always-on categories when ready', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="health-score"]').text()).toContain('72')
		const counts = wrapper
			.findAll('.health-report__counts li')
			.map((li) => li.text())
		expect(counts).toEqual([
			'Weak: 1',
			'Reused: 2',
			'Old: 3',
			'Possibly compromised: 5',
			'Unencrypted address: 6',
		])
		for (const id of ['weak', 'reused', 'stale', 'compromised', 'insecure-address', 'rotation']) {
			expect(wrapper.find(`[data-testid="category-${id}"]`).exists()).toBe(
				true,
			)
		}
		expect(wrapper.find('[data-testid="category-weak"]').text()).toBe('Weak one')
		expect(wrapper.find('[data-testid="category-breached"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="breach-flag-all"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="breach-optin"]').exists()).toBe(false)
	})

	it('shows the analysing notice while the analysis runs', async () => {
		healthStore.status = 'analysing'

		const wrapper = await mountView()

		expect(
			wrapper.find('[data-testid="health-report-analysing"]').exists(),
		).toBe(true)
		expect(wrapper.find('[data-testid="health-score"]').exists()).toBe(false)
	})

	it('shows the store error when the analysis failed', async () => {
		healthStore.status = 'error'
		healthStore.error = 'Analysis exploded'

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="health-report-error"]').text()).toBe(
			'Analysis exploded',
		)
	})

	it('renders nothing below the controls while idle', async () => {
		healthStore.status = 'idle'

		const wrapper = await mountView()

		expect(
			wrapper.find('[data-testid="health-report-analysing"]').exists(),
		).toBe(false)
		expect(wrapper.find('[data-testid="health-score"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="health-report-error"]').exists()).toBe(
			false,
		)
	})

	it('offers the opt-in behind the admin gate and shows breach findings once opted in', async () => {
		state.breachCheckEnabled = true
		axios.get.mockResolvedValue({ data: { breach_check_opt_in: '1' } })

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="breach-optin"]').exists()).toBe(true)
		expect(wrapper.find('.health-report__optin-hint').exists()).toBe(true)
		expect(
			wrapper.find('[data-testid="breach-optin"] input').element.checked,
		).toBe(true)
		expect(wrapper.text()).toContain('Breached: 4')
		expect(wrapper.find('[data-testid="category-breached"]').text()).toBe(
			'Breached one',
		)
		expect(healthStore.analyseVault).toHaveBeenCalledWith({
			stalenessThreshold: '365',
			breachEnabled: true,
		})
	})

	it('flags every breached secret for rotation from the batch button', async () => {
		state.breachCheckEnabled = true
		axios.get.mockResolvedValue({ data: { breach_check_opt_in: true } })

		const wrapper = await mountView()
		await wrapper.find('[data-testid="breach-flag-all"]').trigger('click')
		await flushPromises()

		expect(rotationStore.flagSecrets).toHaveBeenCalledWith(['b1'])
	})

	it('hides the batch button when breach checking found nothing', async () => {
		state.breachCheckEnabled = true
		healthStore.breachedFindings = []
		axios.get.mockResolvedValue({ data: { breach_check_opt_in: '1' } })

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="category-breached"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="breach-flag-all"]').exists()).toBe(false)
	})

	it('says the breach check is unavailable when the service could not be reached', async () => {
		healthStore.breachStatus = 'unavailable'

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="breach-unavailable"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="breach-flag-all"]').exists()).toBe(false)
	})

	it('lists the open rotation flags, naming a nameless one by its id', async () => {
		rotationStore.flags = [
			{ secretId: 's1', secretName: 'Mail' },
			{ secretId: 's2', secretName: '' },
		]

		const wrapper = await mountView()

		expect(
			wrapper
				.findAll('[data-testid="category-rotation"] .finding')
				.map((f) => f.text()),
		).toEqual(['Mail', 's2'])
	})

	it('still analyses when the rotation flags fail to load', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		rotationStore.fetchFlags = vi.fn().mockRejectedValue(new Error('down'))

		const wrapper = await mountView()

		expect(warn).toHaveBeenCalledWith(
			'Keepiq: failed to load rotation flags',
			expect.any(Error),
		)
		expect(healthStore.analyseVault).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="health-score"]').exists()).toBe(true)
	})
})
